<?php

namespace App\Services;

use App\Enums\AdjustmentReason;
use App\Enums\AdjustmentSource;
use App\Enums\AdjustmentStatus;
use App\Enums\AdjustmentTiming;
use App\Enums\BenefitPeriodStatus;
use App\Enums\BenefitType;
use App\Enums\CalendarDayType;
use App\Models\BenefitAdjustment;
use App\Models\BenefitAdjustmentImpact;
use App\Models\BenefitPeriod;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Registro e revisão de ajustes de benefícios (eventos com impactos por tipo).
 *
 * Toda operação roda em transação com bloqueio pessimista da competência, que só
 * aceita alterações em Open ou Calculated; em Calculated a prévia é descartada pelo
 * BenefitPeriodWorkflow antes da gravação. Violações de regra lançam DomainException
 * com mensagem para o usuário. Alertas (DD5/DD6, conflitos) não bloqueiam e são
 * retornados no formato array{level: 'block'|'warning'|'info', message: string}.
 *
 * O serviço não lê batidas de ponto: a descoberta de sugestões é do
 * TimesheetAdjustmentSuggester, que usa registerSuggestion().
 */
class BenefitAdjustmentRegistrar
{
    public function __construct(
        private BusinessCalendar $calendar,
        private BenefitPeriodWorkflow $workflow,
    ) {}

    /**
     * Registra um ajuste. O status inicial vem da origem (Manual/Hr → Confirmed,
     * Timesheet/Import → Pending).
     *
     * @param  array<string, int>  $impacts  somente para o motivo Manual: quantidade assinada por tipo (chave = BenefitType::value)
     * @return array{adjustment: BenefitAdjustment, alerts: list<array{level: string, message: string}>}
     *
     * @throws DomainException
     */
    public function register(
        BenefitPeriod $period,
        Employee $employee,
        AdjustmentReason $reason,
        AdjustmentSource $source,
        CarbonInterface $startsOn,
        CarbonInterface $endsOn,
        ?string $notes = null,
        array $impacts = [],
    ): array {
        $adjustment = DB::transaction(function () use ($period, $employee, $reason, $source, $startsOn, $endsOn, $notes, $impacts) {
            $lockedPeriod = $this->lockEditablePeriod($period);

            return $this->createAdjustment($lockedPeriod, $employee, $reason, $source, $this->toDate($startsOn), $this->toDate($endsOn), $notes, $impacts);
        });

        return $this->result($adjustment);
    }

    /**
     * Registra uma sugestão do ponto (origem Timesheet, status Pending) de forma
     * idempotente: se a dedupe_key já existe, em qualquer status, nada é criado.
     *
     * @return array{adjustment: BenefitAdjustment, alerts: list<array{level: string, message: string}>, created: bool}
     *
     * @throws DomainException
     */
    public function registerSuggestion(BenefitPeriod $period, Employee $employee, AdjustmentReason $reason, CarbonInterface $date, ?string $notes = null): array
    {
        $day = $this->toDate($date);
        $dedupeKey = $this->suggestionKey($period, $employee, $reason, $day);

        try {
            [$adjustment, $created] = DB::transaction(function () use ($period, $employee, $reason, $day, $notes, $dedupeKey) {
                $lockedPeriod = $this->lockEditablePeriod($period);

                $existing = BenefitAdjustment::query()->where('dedupe_key', $dedupeKey)->first();

                if ($existing !== null) {
                    return [$existing, false];
                }

                return [$this->createAdjustment($lockedPeriod, $employee, $reason, AdjustmentSource::Timesheet, $day, $day, $notes, [], $dedupeKey), true];
            });
        } catch (UniqueConstraintViolationException) {
            [$adjustment, $created] = [BenefitAdjustment::query()->where('dedupe_key', $dedupeKey)->firstOrFail(), false];
        }

        return [...$this->result($adjustment), 'created' => $created];
    }

    /**
     * Chave de deduplicação de uma sugestão do ponto (DD1).
     */
    public function suggestionKey(BenefitPeriod $period, Employee $employee, AdjustmentReason $reason, CarbonInterface $date): string
    {
        return 'timesheet:'.$period->id.':'.$employee->id.':'.$date->toDateString().':'.$reason->name;
    }

    /**
     * Altera motivo, datas, observação (e impactos, se Manual) de um ajuste pendente.
     * A origem é preservada: reclassificar uma sugestão do ponto mantém a origem Timesheet.
     *
     * @param  array<string, int>  $impacts
     * @return array{adjustment: BenefitAdjustment, alerts: list<array{level: string, message: string}>}
     *
     * @throws DomainException
     */
    public function update(
        BenefitAdjustment $adjustment,
        AdjustmentReason $reason,
        CarbonInterface $startsOn,
        CarbonInterface $endsOn,
        ?string $notes = null,
        array $impacts = [],
    ): array {
        $adjustment = DB::transaction(function () use ($adjustment, $reason, $startsOn, $endsOn, $notes, $impacts) {
            $lockedPeriod = $this->lockEditablePeriod($adjustment->benefitPeriod);
            $adjustment = $this->lockAdjustment($adjustment);

            if ($adjustment->status !== AdjustmentStatus::Pending) {
                throw new DomainException('Somente ajustes pendentes podem ser alterados. Para um ajuste confirmado, use Substituir.');
            }

            $start = $this->toDate($startsOn);
            $end = $this->toDate($endsOn);

            $prepared = $this->prepare($lockedPeriod, $reason, $adjustment->source, $start, $end, $notes, $impacts, checkReasonSource: false);
            $this->ensureNoDuplicate($lockedPeriod, (int) $adjustment->employee_id, $reason, $start, $end, $adjustment->id);

            $this->invalidateCalculation($lockedPeriod);

            $adjustment->reason = $reason;
            $adjustment->starts_on = $start;
            $adjustment->ends_on = $end;
            $adjustment->days_count = $prepared['days_count'];
            $adjustment->notes = $prepared['notes'];
            $adjustment->updated_by = auth()->id();
            $adjustment->save();

            $adjustment->impacts()->delete();
            $this->insertImpacts($adjustment, $prepared['impacts']);

            return $adjustment;
        });

        return $this->result($adjustment);
    }

    /**
     * Pending → Confirmed. Revalida DD2/DD3 sob bloqueio da competência.
     *
     * @throws DomainException
     */
    public function confirm(BenefitAdjustment $adjustment, ?string $reviewNotes = null): BenefitAdjustment
    {
        return DB::transaction(function () use ($adjustment, $reviewNotes) {
            $lockedPeriod = $this->lockEditablePeriod($adjustment->benefitPeriod);
            $adjustment = $this->lockAdjustment($adjustment);

            if ($adjustment->status !== AdjustmentStatus::Pending) {
                throw new DomainException('Somente ajustes pendentes podem ser confirmados.');
            }

            $this->ensureNoDuplicate($lockedPeriod, (int) $adjustment->employee_id, $adjustment->reason, $adjustment->starts_on, $adjustment->ends_on, $adjustment->id);

            $this->invalidateCalculation($lockedPeriod);

            $this->review($adjustment, AdjustmentStatus::Confirmed, $this->nullableText($reviewNotes));

            return $adjustment;
        });
    }

    /**
     * Pending/Confirmed → Rejected, com nota obrigatória. O ajuste não é apagado.
     *
     * @throws DomainException
     */
    public function reject(BenefitAdjustment $adjustment, string $reviewNotes): BenefitAdjustment
    {
        $reviewNotes = $this->nullableText($reviewNotes);

        if ($reviewNotes === null) {
            throw new DomainException('Informe o motivo da rejeição.');
        }

        return DB::transaction(function () use ($adjustment, $reviewNotes) {
            $lockedPeriod = $this->lockEditablePeriod($adjustment->benefitPeriod);
            $adjustment = $this->lockAdjustment($adjustment);

            if ($adjustment->status === AdjustmentStatus::Rejected) {
                throw new DomainException('O ajuste #'.$adjustment->id.' já está rejeitado.');
            }

            $this->invalidateCalculation($lockedPeriod);

            $this->review($adjustment, AdjustmentStatus::Rejected, $reviewNotes);

            return $adjustment;
        });
    }

    /**
     * Rejected → Pending. O ajuste volta a exigir revisão; a nota da rejeição anterior
     * é preservada em review_notes.
     *
     * @throws DomainException
     */
    public function reconsider(BenefitAdjustment $adjustment, ?string $reviewNotes = null): BenefitAdjustment
    {
        return DB::transaction(function () use ($adjustment, $reviewNotes) {
            $lockedPeriod = $this->lockEditablePeriod($adjustment->benefitPeriod);
            $adjustment = $this->lockAdjustment($adjustment);

            if ($adjustment->status !== AdjustmentStatus::Rejected) {
                throw new DomainException('Somente ajustes rejeitados podem ser reconsiderados.');
            }

            $this->ensureNoDuplicate($lockedPeriod, (int) $adjustment->employee_id, $adjustment->reason, $adjustment->starts_on, $adjustment->ends_on, $adjustment->id);

            $this->invalidateCalculation($lockedPeriod);

            $reviewNotes = $this->nullableText($reviewNotes);

            $notes = collect([
                $reviewNotes !== null ? 'Reconsiderado: '.$reviewNotes : 'Reconsiderado.',
                $adjustment->review_notes !== null ? 'Rejeição anterior: '.$adjustment->review_notes : null,
            ])->filter()->join(' ');

            $this->review($adjustment, AdjustmentStatus::Pending, $notes);

            return $adjustment;
        });
    }

    /**
     * Substitui um ajuste confirmado: em uma transação o original vai para Rejected
     * (nota automática "substituído") e um novo ajuste é criado apontando para ele.
     *
     * @param  array<string, int>  $impacts
     * @return array{adjustment: BenefitAdjustment, alerts: list<array{level: string, message: string}>}
     *
     * @throws DomainException
     */
    public function replace(
        BenefitAdjustment $original,
        AdjustmentReason $reason,
        CarbonInterface $startsOn,
        CarbonInterface $endsOn,
        ?string $notes = null,
        array $impacts = [],
        AdjustmentSource $source = AdjustmentSource::Manual,
        ?string $reviewNotes = null,
    ): array {
        $replacement = DB::transaction(function () use ($original, $reason, $startsOn, $endsOn, $notes, $impacts, $source, $reviewNotes) {
            $lockedPeriod = $this->lockEditablePeriod($original->benefitPeriod);
            $original = $this->lockAdjustment($original);

            if ($original->status !== AdjustmentStatus::Confirmed) {
                throw new DomainException('Somente ajustes confirmados podem ser substituídos.');
            }

            $this->review($original, AdjustmentStatus::Rejected, 'Substituído.');

            $replacement = $this->createAdjustment(
                $lockedPeriod,
                $original->employee,
                $reason,
                $source,
                $this->toDate($startsOn),
                $this->toDate($endsOn),
                $notes,
                $impacts,
                relatedAdjustment: $original,
            );

            $original->review_notes = collect([
                'Substituído pelo ajuste #'.$replacement->id.'.',
                $this->nullableText($reviewNotes),
            ])->filter()->join(' ');
            $original->save();

            return $replacement;
        });

        return $this->result($replacement);
    }

    /**
     * Confirma vários ajustes da competência em uma única transação. Se algum item
     * falhar, nada é confirmado e a exceção lista cada item com o motivo.
     *
     * @param  list<int>  $adjustmentIds
     * @return Collection<int, BenefitAdjustment>
     *
     * @throws DomainException
     */
    public function confirmMany(BenefitPeriod $period, array $adjustmentIds, ?string $reviewNotes = null): Collection
    {
        return $this->reviewMany($period, $adjustmentIds, 'confirmado', fn (BenefitAdjustment $adjustment) => $this->confirm($adjustment, $reviewNotes));
    }

    /**
     * Rejeita vários ajustes da competência em uma única transação, com a mesma nota.
     *
     * @param  list<int>  $adjustmentIds
     * @return Collection<int, BenefitAdjustment>
     *
     * @throws DomainException
     */
    public function rejectMany(BenefitPeriod $period, array $adjustmentIds, string $reviewNotes): Collection
    {
        if ($this->nullableText($reviewNotes) === null) {
            throw new DomainException('Informe o motivo da rejeição.');
        }

        return $this->reviewMany($period, $adjustmentIds, 'rejeitado', fn (BenefitAdjustment $adjustment) => $this->reject($adjustment, $reviewNotes));
    }

    /**
     * Alertas e bloqueios de um ajuste ainda não gravado (ou em edição), para a tela
     * avisar antes de salvar. Bloqueios aqui são revalidados na gravação.
     *
     * @return list<array{level: string, message: string}>
     */
    public function previewAlerts(
        BenefitPeriod $period,
        int $employeeId,
        AdjustmentReason $reason,
        CarbonInterface $startsOn,
        CarbonInterface $endsOn,
        ?int $ignoredAdjustmentId = null,
    ): array {
        $start = $this->toDate($startsOn);
        $end = $this->toDate($endsOn);

        if ($start->greaterThan($end)) {
            return [];
        }

        $others = $this->overlappingAdjustments(collect([$employeeId]), $start, $end)
            ->reject(fn (BenefitAdjustment $other) => $other->id === $ignoredAdjustmentId);

        return $this->alertsAgainst($period->id, $employeeId, $reason, $start, $end, $others);
    }

    /**
     * Alertas de cada ajuste não rejeitado da lista, com uma única consulta.
     *
     * @param  Collection<int, BenefitAdjustment>  $adjustments  ajustes da competência
     * @return array<int, list<array{level: string, message: string}>> chave = id do ajuste
     */
    public function alertsFor(BenefitPeriod $period, Collection $adjustments): array
    {
        $active = $adjustments->reject(fn (BenefitAdjustment $adjustment) => $adjustment->status === AdjustmentStatus::Rejected);

        if ($active->isEmpty()) {
            return [];
        }

        $others = $this->overlappingAdjustments(
            $active->pluck('employee_id')->unique()->values(),
            $active->min(fn (BenefitAdjustment $adjustment) => $adjustment->starts_on),
            $active->max(fn (BenefitAdjustment $adjustment) => $adjustment->ends_on),
        );

        $alerts = [];

        foreach ($active as $adjustment) {
            $alerts[$adjustment->id] = $this->alertsAgainst(
                $period->id,
                (int) $adjustment->employee_id,
                $adjustment->reason,
                $adjustment->starts_on,
                $adjustment->ends_on,
                $others->reject(fn (BenefitAdjustment $other) => $other->id === $adjustment->id),
            );
        }

        return array_filter($alerts);
    }

    /**
     * Quantidade de dias e impactos padrão calculados para um motivo e intervalo.
     * Usado pela tela para explicar o impacto automático antes de salvar.
     *
     * @return int|null null quando o motivo é Manual (impactos livres)
     */
    public function standardDaysCount(AdjustmentReason $reason, CarbonInterface $startsOn, CarbonInterface $endsOn): ?int
    {
        return match (true) {
            $reason->isAbsence() => $this->calendar->businessDays($this->toDate($startsOn), $this->toDate($endsOn))->count(),
            $reason->isWork() => 1,
            default => null,
        };
    }

    /**
     * Valida e grava um novo ajuste com seus impactos. Deve ser chamado dentro da
     * transação, com a competência já bloqueada.
     *
     * @param  array<string, int>  $impacts
     */
    private function createAdjustment(
        BenefitPeriod $lockedPeriod,
        Employee $employee,
        AdjustmentReason $reason,
        AdjustmentSource $source,
        CarbonImmutable $start,
        CarbonImmutable $end,
        ?string $notes,
        array $impacts,
        ?string $dedupeKey = null,
        ?BenefitAdjustment $relatedAdjustment = null,
    ): BenefitAdjustment {
        if (! $employee->exists) {
            throw new DomainException('Funcionário inválido.');
        }

        $prepared = $this->prepare($lockedPeriod, $reason, $source, $start, $end, $notes, $impacts);
        $this->ensureNoDuplicate($lockedPeriod, $employee->id, $reason, $start, $end);

        $this->invalidateCalculation($lockedPeriod);

        $adjustment = new BenefitAdjustment([
            'reason' => $reason,
            'source' => $source,
            'starts_on' => $start,
            'ends_on' => $end,
            'days_count' => $prepared['days_count'],
            'notes' => $prepared['notes'],
            'dedupe_key' => $dedupeKey,
        ]);
        $adjustment->benefitPeriod()->associate($lockedPeriod);
        $adjustment->employee()->associate($employee);
        $adjustment->relatedAdjustment()->associate($relatedAdjustment);
        $adjustment->status = $source->initialStatus();
        $adjustment->created_by = auth()->id();
        $adjustment->updated_by = auth()->id();
        $adjustment->save();

        $this->insertImpacts($adjustment, $prepared['impacts']);

        return $adjustment;
    }

    /**
     * Regras de motivo, origem, datas, observação e impactos.
     *
     * @param  array<string, int>  $impacts
     * @return array{days_count: int, impacts: array<string, int>, notes: ?string, timing: AdjustmentTiming}
     *
     * @throws DomainException
     */
    private function prepare(
        BenefitPeriod $period,
        AdjustmentReason $reason,
        AdjustmentSource $source,
        CarbonImmutable $start,
        CarbonImmutable $end,
        ?string $notes,
        array $impacts,
        bool $checkReasonSource = true,
    ): array {
        $notes = $this->nullableText($notes);

        if ($reason === AdjustmentReason::Manual && $source !== AdjustmentSource::Manual) {
            throw new DomainException('O motivo Ajuste manual só pode ter origem Manual.');
        }

        if ($checkReasonSource && ! in_array($source, $reason->allowedSources(), true)) {
            throw new DomainException('O motivo '.$reason->label().' não pode ter origem '.$source->label().'.');
        }

        if ($start->greaterThan($end)) {
            throw new DomainException('A data inicial deve ser igual ou anterior à data final.');
        }

        $timing = $this->timing($period, $start, $end);

        if (! in_array($timing, $this->allowedTimings($source), true)) {
            throw new DomainException($source === AdjustmentSource::Timesheet
                ? 'Sugestões do ponto devem estar dentro da janela de eventos realizados ('.$period->windowStart()->format('d/m/Y').' a '.$period->windowEnd()->format('d/m/Y').').'
                : 'Ajustes de origem '.$source->label().' não podem ser lançados como '.mb_strtolower($timing->label()).'.');
        }

        if ($timing === AdjustmentTiming::Retroactive && $notes === null) {
            throw new DomainException('Correções retroativas exigem observação.');
        }

        if ($reason->isWork() && ! $start->equalTo($end)) {
            throw new DomainException('O motivo '.$reason->label().' representa um único dia: a data inicial e a final devem ser iguais.');
        }

        if ($reason === AdjustmentReason::Manual) {
            if ($notes === null) {
                throw new DomainException('O ajuste manual exige observação.');
            }

            return [
                'days_count' => (int) $start->diffInDays($end) + 1,
                'impacts' => $this->manualImpacts($impacts),
                'notes' => $notes,
                'timing' => $timing,
            ];
        }

        if ($impacts !== []) {
            throw new DomainException('Os impactos do motivo '.$reason->label().' são automáticos e não podem ser informados.');
        }

        $daysCount = (int) $this->standardDaysCount($reason, $start, $end);

        if ($daysCount === 0) {
            throw new DomainException('O intervalo não contém dias úteis: sábados, domingos e feriados não são descontados.');
        }

        $quantity = $reason->defaultSign() * $daysCount;

        return [
            'days_count' => $daysCount,
            'impacts' => collect(BenefitType::cases())->mapWithKeys(fn (BenefitType $type) => [$type->value => $quantity])->all(),
            'notes' => $notes,
            'timing' => $timing,
        ];
    }

    /**
     * @param  array<string, mixed>  $impacts
     * @return array<string, int>
     *
     * @throws DomainException
     */
    private function manualImpacts(array $impacts): array
    {
        $normalized = [];

        foreach ($impacts as $type => $quantity) {
            $benefitType = BenefitType::tryFrom((string) $type);

            if ($benefitType === null) {
                throw new DomainException('Tipo de benefício inválido: '.$type.'.');
            }

            if (! is_int($quantity)) {
                throw new DomainException('A quantidade de '.$benefitType->label().' deve ser um número inteiro.');
            }

            if (abs($quantity) > 999) {
                throw new DomainException('A quantidade de '.$benefitType->label().' deve estar entre -999 e 999.');
            }

            if ($quantity !== 0) {
                $normalized[$benefitType->value] = $quantity;
            }
        }

        if ($normalized === []) {
            throw new DomainException('Informe ao menos um impacto diferente de zero para o ajuste manual.');
        }

        return $normalized;
    }

    /**
     * @throws DomainException
     */
    private function timing(BenefitPeriod $period, CarbonImmutable $start, CarbonImmutable $end): AdjustmentTiming
    {
        $timing = $period->timingOf($start, $end);

        if ($timing !== null) {
            return $timing;
        }

        if ($end->greaterThan($period->monthEnd())) {
            throw new DomainException('Datas posteriores ao fim da competência ('.$period->monthEnd()->format('d/m/Y').') não são permitidas.');
        }

        throw new DomainException('O intervalo mistura eventos previstos, realizados e retroativos. Lance um ajuste para cada mês.');
    }

    /**
     * Classes de evento permitidas por origem (Spec §8.4).
     *
     * @return list<AdjustmentTiming>
     */
    private function allowedTimings(AdjustmentSource $source): array
    {
        return match ($source) {
            AdjustmentSource::Manual, AdjustmentSource::Hr => [AdjustmentTiming::Realized, AdjustmentTiming::Forecast, AdjustmentTiming::Retroactive],
            AdjustmentSource::Timesheet => [AdjustmentTiming::Realized],
            AdjustmentSource::Import => [AdjustmentTiming::Realized, AdjustmentTiming::Retroactive],
        };
    }

    /**
     * DD2/DD3: na mesma competência, no máximo um evento não rejeitado de ausência
     * (ou de trabalho) por funcionário e dia. O motivo Manual não é verificado (DD5).
     *
     * @throws DomainException
     */
    private function ensureNoDuplicate(BenefitPeriod $period, int $employeeId, AdjustmentReason $reason, CarbonInterface $start, CarbonInterface $end, ?int $ignoredAdjustmentId = null): void
    {
        $sameKindReasons = $this->sameKindReasons($reason);

        if ($sameKindReasons === []) {
            return;
        }

        $duplicate = BenefitAdjustment::query()
            ->where('benefit_period_id', $period->id)
            ->where('employee_id', $employeeId)
            ->where('status', '!=', AdjustmentStatus::Rejected)
            ->whereIn('reason', $sameKindReasons)
            ->where('starts_on', '<=', $end->toDateString())
            ->where('ends_on', '>=', $start->toDateString())
            ->when($ignoredAdjustmentId !== null, fn ($query) => $query->whereKeyNot($ignoredAdjustmentId))
            ->first();

        if ($duplicate === null) {
            return;
        }

        throw new DomainException(($reason->isAbsence()
            ? 'Já existe uma ausência não rejeitada para este funcionário neste dia'
            : 'Já existe um trabalho não rejeitado para este funcionário neste dia')
            .': '.$this->describe($duplicate).'.');
    }

    /**
     * @return list<AdjustmentReason>
     */
    private function sameKindReasons(AdjustmentReason $reason): array
    {
        return match (true) {
            $reason->isAbsence() => array_values(array_filter(AdjustmentReason::cases(), fn (AdjustmentReason $case) => $case->isAbsence())),
            $reason->isWork() => array_values(array_filter(AdjustmentReason::cases(), fn (AdjustmentReason $case) => $case->isWork())),
            default => [],
        };
    }

    /**
     * Ajustes não rejeitados dos funcionários que tocam o intervalo, em qualquer competência.
     *
     * @param  Collection<int, int>  $employeeIds
     * @return Collection<int, BenefitAdjustment>
     */
    private function overlappingAdjustments(Collection $employeeIds, CarbonInterface $start, CarbonInterface $end): Collection
    {
        return BenefitAdjustment::query()
            ->with('benefitPeriod:id,competence')
            ->whereIn('employee_id', $employeeIds)
            ->where('status', '!=', AdjustmentStatus::Rejected)
            ->where('starts_on', '<=', $end->toDateString())
            ->where('ends_on', '>=', $start->toDateString())
            ->get();
    }

    /**
     * @param  Collection<int, BenefitAdjustment>  $others  ajustes não rejeitados (o próprio já excluído)
     * @return list<array{level: string, message: string}>
     */
    private function alertsAgainst(int $periodId, int $employeeId, AdjustmentReason $reason, CarbonInterface $start, CarbonInterface $end, Collection $others): array
    {
        $alerts = $this->dayTypeAlerts($reason, $start);

        $overlapping = $others->filter(fn (BenefitAdjustment $other) => (int) $other->employee_id === $employeeId
            && $other->starts_on->toDateString() <= $end->toDateString()
            && $other->ends_on->toDateString() >= $start->toDateString());

        foreach ($overlapping as $other) {
            if ((int) $other->benefit_period_id !== $periodId) {
                $alerts[] = [
                    'level' => 'info',
                    'message' => 'Este funcionário já possui efeito registrado para esta data na competência '.$other->benefitPeriod->competence->format('m/Y').': '.$this->describe($other).'.',
                ];

                continue;
            }

            $alerts[] = match (true) {
                $reason === AdjustmentReason::Manual || $other->reason === AdjustmentReason::Manual => [
                    'level' => 'warning',
                    'message' => 'Já existe outro ajuste para este funcionário nesta data: '.$this->describe($other).'.',
                ],
                in_array($other->reason, $this->sameKindReasons($reason), true) => [
                    'level' => 'block',
                    'message' => ($reason->isAbsence()
                        ? 'Já existe uma ausência não rejeitada para este funcionário neste dia'
                        : 'Já existe um trabalho não rejeitado para este funcionário neste dia').': '.$this->describe($other).'.',
                ],
                default => [
                    'level' => 'warning',
                    'message' => 'Conflito: ausência e trabalho no mesmo dia: '.$this->describe($other).'.',
                ],
            };
        }

        return $alerts;
    }

    /**
     * Motivos de trabalho cuja data não corresponde ao tipo de dia do calendário.
     * Apenas alerta: o feriado pode ter sido cadastrado depois do lançamento.
     *
     * @return list<array{level: string, message: string}>
     */
    private function dayTypeAlerts(AdjustmentReason $reason, CarbonInterface $date): array
    {
        $expected = match ($reason) {
            AdjustmentReason::SaturdayWorked => CalendarDayType::Saturday,
            AdjustmentReason::SundayWorked => CalendarDayType::Sunday,
            AdjustmentReason::HolidayWorked => CalendarDayType::Holiday,
            default => null,
        };

        if ($expected === null || $this->calendar->classify($date) === $expected) {
            return [];
        }

        return [[
            'level' => 'warning',
            'message' => 'A data '.$date->format('d/m/Y').' não é '.match ($expected) {
                CalendarDayType::Saturday => 'um sábado',
                CalendarDayType::Sunday => 'um domingo',
                default => 'um feriado cadastrado',
            }.' no calendário.',
        ]];
    }

    /**
     * @param  list<int>  $adjustmentIds
     * @param  callable(BenefitAdjustment): BenefitAdjustment  $operation
     * @return Collection<int, BenefitAdjustment>
     *
     * @throws DomainException
     */
    private function reviewMany(BenefitPeriod $period, array $adjustmentIds, string $verb, callable $operation): Collection
    {
        $adjustmentIds = array_values(array_unique(array_map('intval', $adjustmentIds)));

        if ($adjustmentIds === []) {
            throw new DomainException('Selecione ao menos um ajuste.');
        }

        return DB::transaction(function () use ($period, $adjustmentIds, $verb, $operation) {
            $this->lockEditablePeriod($period);

            $adjustments = BenefitAdjustment::query()
                ->with(['benefitPeriod', 'employee:id,name'])
                ->whereKey($adjustmentIds)
                ->get()
                ->keyBy('id');

            $reviewed = collect();
            $failures = [];

            foreach ($adjustmentIds as $adjustmentId) {
                $adjustment = $adjustments->get($adjustmentId);

                if ($adjustment === null || (int) $adjustment->benefit_period_id !== $period->id) {
                    $failures[] = 'Ajuste #'.$adjustmentId.': não pertence a esta competência.';

                    continue;
                }

                try {
                    $reviewed->push($operation($adjustment));
                } catch (DomainException $exception) {
                    $failures[] = 'Ajuste #'.$adjustment->id.' ('.$adjustment->employee->name.', '.$this->interval($adjustment).'): '.$exception->getMessage();
                }
            }

            if ($failures !== []) {
                throw new DomainException('Nenhum ajuste foi '.$verb.'. '.implode(' ', $failures));
            }

            return $reviewed;
        });
    }

    /**
     * Bloqueia a competência para a operação e garante que ela aceita alterações.
     *
     * @throws DomainException
     */
    private function lockEditablePeriod(BenefitPeriod $period): BenefitPeriod
    {
        $lockedPeriod = BenefitPeriod::query()->lockForUpdate()->findOrFail($period->id);

        if (! $lockedPeriod->status->isEditable()) {
            throw new DomainException('A competência '.$lockedPeriod->competence->format('m/Y').' está fechada: ajustes não podem ser criados nem alterados.');
        }

        return $lockedPeriod;
    }

    private function lockAdjustment(BenefitAdjustment $adjustment): BenefitAdjustment
    {
        return BenefitAdjustment::query()->with('employee')->lockForUpdate()->findOrFail($adjustment->id);
    }

    /**
     * Uma alteração em competência Calculated descarta a prévia (Calculated → Open).
     */
    private function invalidateCalculation(BenefitPeriod $lockedPeriod): void
    {
        if ($lockedPeriod->status !== BenefitPeriodStatus::Calculated) {
            return;
        }

        $this->workflow->invalidate($lockedPeriod, 'Prévia descartada por alteração de ajustes.');
        $lockedPeriod->status = BenefitPeriodStatus::Open;
    }

    private function review(BenefitAdjustment $adjustment, AdjustmentStatus $status, ?string $reviewNotes): void
    {
        $adjustment->status = $status;
        $adjustment->reviewed_by = auth()->id();
        $adjustment->reviewed_at = now();
        $adjustment->review_notes = $reviewNotes;
        $adjustment->updated_by = auth()->id();
        $adjustment->save();
    }

    /**
     * Grava os impactos em uma única instrução.
     *
     * @param  array<string, int>  $impacts
     */
    private function insertImpacts(BenefitAdjustment $adjustment, array $impacts): void
    {
        $now = now();

        BenefitAdjustmentImpact::query()->insert(collect($impacts)
            ->map(fn (int $quantity, string $type) => [
                'benefit_adjustment_id' => $adjustment->id,
                'benefit_type' => $type,
                'quantity' => $quantity,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->values()
            ->all());
    }

    /**
     * @return array{adjustment: BenefitAdjustment, alerts: list<array{level: string, message: string}>}
     */
    private function result(BenefitAdjustment $adjustment): array
    {
        $adjustment->load(['benefitPeriod', 'impacts']);

        return [
            'adjustment' => $adjustment,
            'alerts' => $adjustment->status === AdjustmentStatus::Rejected ? [] : $this->previewAlerts(
                $adjustment->benefitPeriod,
                (int) $adjustment->employee_id,
                $adjustment->reason,
                $adjustment->starts_on,
                $adjustment->ends_on,
                $adjustment->id,
            ),
        ];
    }

    private function describe(BenefitAdjustment $adjustment): string
    {
        return $adjustment->reason->label().' em '.$this->interval($adjustment).' (#'.$adjustment->id.', '.mb_strtolower($adjustment->status->label()).')';
    }

    private function interval(BenefitAdjustment $adjustment): string
    {
        return $adjustment->starts_on->equalTo($adjustment->ends_on)
            ? $adjustment->starts_on->format('d/m/Y')
            : $adjustment->starts_on->format('d/m/Y').' a '.$adjustment->ends_on->format('d/m/Y');
    }

    private function toDate(CarbonInterface $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date->toDateString());
    }

    private function nullableText(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : $text;
    }
}
