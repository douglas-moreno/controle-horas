<?php

namespace App\Services;

use App\Enums\AdjustmentReason;
use App\Enums\AdjustmentSource;
use App\Enums\AdjustmentStatus;
use App\Enums\BenefitPeriodStatus;
use App\Enums\CalendarDayType;
use App\Models\BenefitAdjustment;
use App\Models\BenefitPeriod;
use App\Models\BenefitSuggestionRun;
use App\Models\Employee;
use App\Models\Point;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Gera sugestões de ajuste (origem Timesheet, status Pending) a partir das batidas da
 * janela de eventos realizados (mês M−1) e calcula conflitos para revisão.
 *
 * A geração é incremental: processa a janela somente até o horizonte de cobertura
 * (último dia importado − 1), registra cada execução em BenefitSuggestionRun e pode
 * ser repetida após novas importações. Dia útil sem nenhuma batida importada não gera
 * ausência: é sinalizado como conflito.
 *
 * Compensação (F12/S3): quando a competência anterior (origem) está fechada e sua
 * cobertura registrada (maior processed_to das execuções) não alcança o fim da sua
 * janela, os dias restantes ("cauda"), até o horizonte, são analisados com as mesmas
 * regras e os eventos são sugeridos nesta competência por
 * BenefitAdjustmentRegistrar::registerCompensation(), somente para participantes das
 * duas competências.
 *
 * Os conflitos são diagnósticos calculados a cada consulta e nunca gravados: comparam
 * os ajustes existentes com as evidências atuais do ponto (batidas, horizonte e
 * histórico de execuções) e não alteram ajustes em nenhum status.
 *
 * Somente lê `points`. Qualquer batida no dia é presença (R6). As sugestões são
 * criadas exclusivamente pelo BenefitAdjustmentRegistrar::registerSuggestion(), que
 * aplica as regras do ajuste, a deduplicação e a invalidação de competência calculada.
 * Conflitos não são persistidos.
 *
 * Datas de `points` são comparadas pelos 10 primeiros caracteres: a importação AFD
 * grava "Y-m-d" e o lançamento manual (cast `date` do model legado) grava
 * "Y-m-d 00:00:00" no SQLite.
 */
class TimesheetAdjustmentSuggester
{
    public function __construct(
        private BusinessCalendar $calendar,
        private BenefitEligibility $eligibility,
        private BenefitAdjustmentRegistrar $registrar,
    ) {}

    /**
     * Data da última batida importada do AFD. Batidas manuais não contam, pois podem
     * ter sido lançadas antes da importação.
     */
    public function lastImportedPointDate(): ?CarbonImmutable
    {
        $date = Point::query()->where('type', 'importado')->max('date');

        return $date === null ? null : CarbonImmutable::parse(substr((string) $date, 0, 10));
    }

    /**
     * Cobertura da importação para a competência. O horizonte é o último dia importado
     * menos um: o dia mais recente pode estar incompleto. A premissa é um único AFD
     * acumulado e cronológico; não é prova de que cada dia anterior foi importado.
     * `from`/`to` formam o intervalo processável da janela, ou null quando não há.
     *
     * @return array{last_imported: ?CarbonImmutable, horizon: ?CarbonImmutable, from: ?CarbonImmutable, to: ?CarbonImmutable}
     */
    public function importCoverage(BenefitPeriod $period): array
    {
        $lastImported = $this->lastImportedPointDate();
        $horizon = $lastImported?->subDay();
        $processable = $horizon !== null && $horizon->greaterThanOrEqualTo($period->windowStart());

        return [
            'last_imported' => $lastImported,
            'horizon' => $horizon,
            'from' => $processable ? $period->windowStart() : null,
            'to' => $processable ? $horizon->min($period->windowEnd()) : null,
        ];
    }

    /**
     * Motivo pelo qual a geração não pode rodar, ou null quando pode.
     *
     * @param  array{last_imported: ?CarbonImmutable, horizon: ?CarbonImmutable, from: ?CarbonImmutable, to: ?CarbonImmutable}|null  $coverage
     */
    public function generationBlocker(BenefitPeriod $period, ?array $coverage = null): ?string
    {
        if (! $period->status->isEditable()) {
            return 'A competência '.$period->competence->format('m/Y').' está fechada: sugestões não podem ser geradas.';
        }

        $coverage ??= $this->importCoverage($period);

        if ($coverage['last_imported'] === null) {
            return 'Nenhuma batida importada. A geração exige o ponto importado a partir de '.$period->windowStart()->format('d/m/Y').'.';
        }

        if ($coverage['to'] === null) {
            return 'Batidas importadas até '.$coverage['last_imported']->format('d/m/Y').': o ponto é considerado completo somente até '
                .$coverage['horizon']->format('d/m/Y').', antes do início da janela ('.$period->windowStart()->format('d/m/Y').'). '
                .'A geração exige ao menos um dia completo na janela.';
        }

        return null;
    }

    /**
     * Cauda da competência anterior que esta competência pode compensar (F12/S3).
     *
     * Status: `none` (sem competência anterior ou anterior não fechada), `no_history`
     * (anterior fechada sem execução registrada: nenhuma cauda é presumida), `complete`
     * (cobertura registrada até o fim da janela da anterior), `waiting` (horizonte antes
     * do início da cauda) e `processable`. `from` = dia seguinte à cobertura registrada;
     * `to` = min(fim da janela da origem, horizonte); `pending_*` = dias da cauda ainda
     * aguardando importação.
     *
     * @param  array{last_imported: ?CarbonImmutable, horizon: ?CarbonImmutable, from: ?CarbonImmutable, to: ?CarbonImmutable}|null  $importCoverage
     * @param  bool  $originResolved  `$origin` já é a competência anterior (inclusive null, quando não existe)
     * @return array{status: string, origin: ?BenefitPeriod, origin_run: ?BenefitSuggestionRun, covered_until: ?CarbonImmutable, from: ?CarbonImmutable, to: ?CarbonImmutable, window_end: ?CarbonImmutable, pending_from: ?CarbonImmutable, pending_days: int}
     */
    public function tailCoverage(BenefitPeriod $period, ?array $importCoverage = null, ?BenefitPeriod $origin = null, bool $originResolved = false): array
    {
        if (! $originResolved) {
            $origin = $period->previousPeriod();
        }

        $tail = [
            'status' => 'none',
            'origin' => null,
            'origin_run' => null,
            'covered_until' => null,
            'from' => null,
            'to' => null,
            'window_end' => null,
            'pending_from' => null,
            'pending_days' => 0,
        ];

        if ($origin === null || $origin->status !== BenefitPeriodStatus::Closed) {
            return $tail;
        }

        $tail['origin'] = $origin;
        $tail['window_end'] = $origin->windowEnd();
        $originRun = $origin->coverageRun();

        if ($originRun === null) {
            return [...$tail, 'status' => 'no_history'];
        }

        $tail['origin_run'] = $originRun;
        $tail['covered_until'] = $originRun->processed_to;

        if ($originRun->processed_to->greaterThanOrEqualTo($origin->windowEnd())) {
            return [...$tail, 'status' => 'complete'];
        }

        $from = $originRun->processed_to->addDay();
        $importCoverage ??= $this->importCoverage($period);
        $to = $importCoverage['horizon']?->min($origin->windowEnd());
        $processable = $to !== null && $to->greaterThanOrEqualTo($from);
        $pendingFrom = $processable ? $to->addDay() : $from;
        $hasPending = $pendingFrom->lessThanOrEqualTo($origin->windowEnd());

        return [
            ...$tail,
            'status' => $processable ? 'processable' : 'waiting',
            'from' => $from,
            'to' => $processable ? $to : null,
            'pending_from' => $hasPending ? $pendingFrom : null,
            'pending_days' => $hasPending ? (int) $pendingFrom->diffInDays($origin->windowEnd()) + 1 : 0,
        ];
    }

    /**
     * Gera as sugestões da competência de forma idempotente (DD1 + DD4), somente no
     * intervalo processável da janela, compensa a cauda da competência anterior fechada
     * (F12/S3) e registra a execução. Origem e destino são bloqueados nesta ordem (a
     * mesma de BenefitPeriodWorkflow::reopen); tudo ocorre em uma única transação.
     *
     * @return array{created: int, skipped_existing: int, skipped_covered: int, skipped_unobserved_days: int, compensation_created: int, compensation_skipped_existing: int, compensation_skipped_covered: int, compensation_skipped_unobserved_days: int, compensation_not_compensable: int, last_imported: string, horizon: string, processed_from: string, processed_to: string, tail_status: string, tail_from: ?string, tail_to: ?string}
     *
     * @throws DomainException quando a geração está bloqueada ou uma sugestão viola as regras do registro
     */
    public function generate(BenefitPeriod $period): array
    {
        return DB::transaction(function () use ($period) {
            $originId = $period->previousPeriod()?->id;
            $origin = $originId === null ? null : BenefitPeriod::query()->lockForUpdate()->findOrFail($originId);
            $period = BenefitPeriod::query()->lockForUpdate()->findOrFail($period->id);

            $importCoverage = $this->importCoverage($period);
            $blocker = $this->generationBlocker($period, $importCoverage);

            if ($blocker !== null) {
                throw new DomainException($blocker);
            }

            $processedTo = $importCoverage['to']->toDateString();
            $tail = $this->tailCoverage($period, $importCoverage, $origin, originResolved: true);
            $tailProcessable = $tail['status'] === 'processable';

            $originIds = $tailProcessable ? $this->eligibility->participants($origin->referenceDate())->keys() : collect();
            $destinationIds = $tailProcessable ? $this->eligibility->participants($period->referenceDate())->keys() : collect();
            $compensableIds = $originIds->intersect($destinationIds)->values();

            $analysis = $this->analyze($period, $originIds->diff($destinationIds)->values()->all(), $tailProcessable ? $tail['from'] : null);

            if ($analysis['duplicated_pis'] !== []) {
                throw new DomainException('Geração bloqueada: participantes com o mesmo PIS. '.collect($analysis['duplicated_pis'])
                    ->map(fn (Collection $employees, string $pis) => 'PIS '.$pis.': '.$employees->pluck('name')->join(', '))
                    ->join('; ').'.');
            }

            $existingKeys = BenefitAdjustment::query()
                ->where('benefit_period_id', $period->id)
                ->whereNotNull('dedupe_key')
                ->pluck('dedupe_key')
                ->flip();

            $summary = [
                'created' => 0,
                'skipped_existing' => 0,
                'skipped_covered' => 0,
                'skipped_unobserved_days' => 0,
                'compensation_created' => 0,
                'compensation_skipped_existing' => 0,
                'compensation_skipped_covered' => 0,
                'compensation_skipped_unobserved_days' => 0,
                'compensation_not_compensable' => 0,
            ];

            foreach ($analysis['days'] as $date => $dayType) {
                if ($date > $processedTo) {
                    break;
                }

                if ($dayType === CalendarDayType::BusinessDay && ! isset($analysis['imported_dates'][$date])) {
                    $summary['skipped_unobserved_days']++;

                    continue;
                }

                foreach ($analysis['employees'] as $employee) {
                    $punched = isset($analysis['punched'][$employee->id][$date]);
                    $reason = $this->suggestedReason($dayType, $punched);

                    if ($reason === null) {
                        continue;
                    }

                    $day = CarbonImmutable::parse($date);

                    if ($existingKeys->has($this->registrar->suggestionKey($period, $employee, $reason, $day))) {
                        $summary['skipped_existing']++;

                        continue;
                    }

                    $coverage = $reason->isAbsence() ? $analysis['covered_absence'] : $analysis['covered_work'];

                    if (isset($coverage[$employee->id][$date])) {
                        $summary['skipped_covered']++;

                        continue;
                    }

                    $result = $this->registrar->registerSuggestion($period, $employee, $reason, $day);

                    $summary[$result['created'] ? 'created' : 'skipped_existing']++;
                }
            }

            if ($tailProcessable) {
                $findings = $this->tailEvents($tail, $analysis, $analysis['tracked']->whereIn('id', $originIds), $origin);
                $summary['compensation_skipped_unobserved_days'] = $findings['unobserved_days'];

                foreach ($findings['events'] as $event) {
                    if (! $compensableIds->contains($event['employee']->id)) {
                        if ($event['state'] === 'new') {
                            $summary['compensation_not_compensable']++;
                        }

                        continue;
                    }

                    if ($event['state'] !== 'new') {
                        $summary[$event['state'] === 'existing' ? 'compensation_skipped_existing' : 'compensation_skipped_covered']++;

                        continue;
                    }

                    $day = CarbonImmutable::parse($event['date']);
                    $result = $this->registrar->registerCompensation($period, $origin, $event['employee'], $event['reason'], $day, $this->compensationNotes($tail, $day, $importCoverage));

                    if ($result['created']) {
                        $summary['compensation_created']++;
                        $summary['created']++;
                    } else {
                        $summary['compensation_skipped_existing']++;
                    }
                }
            }

            $run = new BenefitSuggestionRun([
                ...$summary,
                'last_imported_on' => $importCoverage['last_imported'],
                'horizon_on' => $importCoverage['horizon'],
                'processed_from' => $importCoverage['from'],
                'processed_to' => $importCoverage['to'],
            ]);
            $run->benefitPeriod()->associate($period);
            $run->user_id = auth()->id();

            if ($tail['status'] !== 'none') {
                $run->tail_origin_period_id = $tail['origin']->id;
                $run->tail_origin_run_id = $tail['origin_run']?->id;
                $run->tail_origin_covered_until = $tail['covered_until'];
                $run->tail_from = $tail['from'];
                $run->tail_to = $tail['to'];
                $run->tail_pending_days = $tail['pending_days'];
            }

            $run->save();

            return [
                ...$summary,
                'last_imported' => $importCoverage['last_imported']->toDateString(),
                'horizon' => $importCoverage['horizon']->toDateString(),
                'processed_from' => $importCoverage['from']->toDateString(),
                'processed_to' => $processedTo,
                'tail_status' => $tail['status'],
                'tail_from' => $tail['from']?->toDateString(),
                'tail_to' => $tail['to']?->toDateString(),
            ];
        });
    }

    /**
     * Conflitos calculados sob demanda para a revisão (não persistidos). Somente lê:
     * nenhum ajuste, execução ou batida é criado ou alterado.
     *
     * Conflitos de ajuste (mutuamente exclusivos por ajuste e dia):
     * - absence_with_punch / work_on_absence: ausência não rejeitada (qualquer origem) em dia com batida;
     * - work_without_punch: trabalho do ponto, dentro do ponto coberto, sem batida do funcionário;
     * - suggestion_beyond_coverage: sugestão do ponto após o ponto coberto atual, em data já processada por uma execução;
     * - suggestion_coverage_unknown: idem, sem execução registrada que tenha processado a data;
     * - absence_without_imported_punches: ausência do ponto em dia útil coberto que perdeu todas as batidas importadas.
     * Compensações (F12/S3) são avaliadas na data original do evento, fora da janela da
     * página, com o ponto considerado completo até o horizonte.
     *
     * Conflitos de cauda (eventos sem ajuste):
     * - uncompensated_tail (C6): evento da cauda de uma origem fechada cuja competência de
     *   destino está fechada; exibido na página do destino e na da competência seguinte;
     * - tail_not_compensable (C7): evento da cauda de funcionário que participava da origem
     *   e não participa do destino.
     *
     * @return list<array{type: string, employee_id: ?int, date: ?string, message: string, adjustment_id: ?int, coverage: ?string, action: ?string}>
     */
    public function conflicts(BenefitPeriod $period): array
    {
        $importCoverage = $this->importCoverage($period);
        $processedTo = $importCoverage['to']?->toDateString();

        $timesheetAdjustments = BenefitAdjustment::query()
            ->where('benefit_period_id', $period->id)
            ->where('source', AdjustmentSource::Timesheet)
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get(['id', 'benefit_period_id', 'employee_id', 'reason', 'status', 'source', 'starts_on', 'ends_on', 'dedupe_key']);

        $activeTimesheetAdjustments = $timesheetAdjustments->reject(fn (BenefitAdjustment $adjustment) => $adjustment->status === AdjustmentStatus::Rejected);
        $activeCompensations = $activeTimesheetAdjustments->filter(fn (BenefitAdjustment $adjustment) => $this->registrar->isCompensation($adjustment));

        $origin = $period->previousPeriod();
        $tail = $this->tailCoverage($period, $importCoverage, $origin, originResolved: true);
        $previousTail = $origin?->status === BenefitPeriodStatus::Closed
            ? $this->tailCoverage($origin, $importCoverage)
            : null;

        $participantIds = [];
        $participants = function (BenefitPeriod $of) use (&$participantIds): Collection {
            return $participantIds[$of->id] ??= $this->eligibility->participants($of->referenceDate())->keys();
        };

        $tailOriginIds = $tail['status'] === 'processable' ? $participants($tail['origin']) : collect();
        $tailCompensableIds = $tailOriginIds->intersect($participants($period))->values();
        $previousTailCompensableIds = $previousTail !== null && $previousTail['status'] === 'processable'
            ? $participants($previousTail['origin'])->intersect($participants($origin))->values()
            : collect();

        $analysisStart = collect([
            $tail['status'] === 'processable' ? $tail['from'] : null,
            $previousTail !== null && $previousTail['status'] === 'processable' ? $previousTail['from'] : null,
            $activeCompensations->min(fn (BenefitAdjustment $adjustment) => $adjustment->starts_on),
        ])->filter()->min();

        $analysis = $this->analyze(
            $period,
            $activeTimesheetAdjustments->pluck('employee_id')->map(fn ($id) => (int) $id)
                ->merge($tailOriginIds)
                ->merge($previousTailCompensableIds)
                ->unique()->values()->all(),
            $analysisStart,
        );
        $tracked = $analysis['tracked']->keyBy('id');

        $runs = $period->suggestionRuns()->get(['id', 'processed_to', 'horizon_on', 'tail_from', 'tail_to', 'created', 'created_at']);
        $allTimesheetCreatedByRuns = $this->allTimesheetCreatedByRuns($timesheetAdjustments, $runs);

        $periodIds = collect($analysis['covered_absence'])->flatten()->map(fn (BenefitAdjustment $adjustment) => (int) $adjustment->benefit_period_id)
            ->merge($activeCompensations->map(fn (BenefitAdjustment $adjustment) => $this->compensationOriginId($adjustment)))
            ->push($period->id)
            ->filter()
            ->unique()
            ->values();
        $periods = BenefitPeriod::query()->whereKey($periodIds)->get(['id', 'competence', 'status'])->keyBy('id');

        $conflicts = [];

        foreach ($analysis['employees'] as $employee) {
            $recisionDate = trim((string) $employee->recision_date);

            if ($recisionDate !== '') {
                $conflicts[] = $this->conflict(
                    'employee_rescinded',
                    $employee->id,
                    null,
                    $employee->name.' possui data de rescisão ('.$this->formatDate($recisionDate).') e continua participando pela vigência de benefício. Encerre as vigências se o funcionário não deve receber.',
                );
            }
        }

        foreach ($analysis['days'] as $date => $dayType) {
            foreach ($tracked as $employee) {
                if (! isset($analysis['punched'][$employee->id][$date], $analysis['covered_absence'][$employee->id][$date])) {
                    continue;
                }

                $formattedDate = CarbonImmutable::parse($date)->format('d/m/Y');

                foreach ($analysis['covered_absence'][$employee->id][$date] as $absence) {
                    $absencePeriod = $periods->get((int) $absence->benefit_period_id);
                    $affected = ' Ajuste afetado: '.$this->describeAdjustment($absence, $date, $absencePeriod).'.';

                    $conflicts[] = $dayType === CalendarDayType::BusinessDay
                        ? $this->conflict(
                            'absence_with_punch',
                            $employee->id,
                            $date,
                            $employee->name.' teve uma ausência registrada para o dia '.$formattedDate.', mas existe batida no ponto.'.$affected,
                            $absence->id,
                            $this->coverageState($date, $importCoverage, isset($analysis['imported_dates'][$date])),
                            $this->recommendedAction($absence, $absencePeriod),
                        )
                        : $this->conflict(
                            'work_on_absence',
                            $employee->id,
                            $date,
                            $employee->name.' tem batida em '.$formattedDate.' ('.$this->dayTypeLabel($dayType).') durante uma ausência registrada.'.$affected,
                            $absence->id,
                            $this->coverageState($date, $importCoverage, isset($analysis['imported_dates'][$date])),
                            $this->recommendedAction($absence, $absencePeriod),
                        );
                }
            }
        }

        foreach ($activeTimesheetAdjustments as $adjustment) {
            $employee = $tracked->get((int) $adjustment->employee_id);

            if ($employee === null) {
                continue;
            }

            $isCompensation = $this->registrar->isCompensation($adjustment);
            $start = $isCompensation ? $adjustment->starts_on : $adjustment->starts_on->max($period->windowStart());
            $end = $isCompensation ? $adjustment->starts_on : $adjustment->ends_on->min($period->windowEnd());

            for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
                $conflict = $this->timesheetConflict(
                    $adjustment,
                    $employee,
                    $day->toDateString(),
                    $analysis,
                    $importCoverage,
                    $runs,
                    $allTimesheetCreatedByRuns,
                    $period,
                    $isCompensation ? $periods->get($this->compensationOriginId($adjustment)) : null,
                );

                if ($conflict !== null) {
                    $conflicts[] = $conflict;
                }
            }
        }

        if ($tail['status'] === 'processable') {
            $findings = $this->tailEvents($tail, $analysis, $tracked->whereIn('id', $tailOriginIds), $tail['origin']);

            foreach ($findings['events'] as $event) {
                if ($event['state'] !== 'new') {
                    continue;
                }

                if (! $tailCompensableIds->contains($event['employee']->id)) {
                    $conflicts[] = $this->conflict(
                        'tail_not_compensable',
                        $event['employee']->id,
                        $event['date'],
                        $event['employee']->name.' participava da competência '.$tail['origin']->competence->format('m/Y').' e não participa de '.$period->competence->format('m/Y')
                            .' (sem elegibilidade em '.$period->referenceDate()->format('d/m/Y').'): o evento de '.CarbonImmutable::parse($event['date'])->format('d/m/Y').' ('.$event['reason']->label().'), na cauda da competência de origem, não pode ser compensado automaticamente.',
                        coverage: $this->tailCoverageState($tail, $importCoverage),
                        action: 'Sem elegibilidade em '.$period->competence->format('m/Y').' um ajuste não teria efeito no cálculo: trate o evento administrativamente.',
                    );

                    continue;
                }

                if ($period->status === BenefitPeriodStatus::Closed) {
                    $conflicts[] = $this->uncompensatedTailConflict($event, $tail, $period, $importCoverage);
                }
            }
        }

        if ($tail['status'] === 'processable') {
            for ($day = $tail['from']; $day->lessThanOrEqualTo($tail['to']); $day = $day->addDay()) {
                if ($analysis['earlier_days']->get($day->toDateString()) === CalendarDayType::BusinessDay && ! isset($analysis['imported_dates'][$day->toDateString()])) {
                    $conflicts[] = $this->conflict(
                        'day_without_punches',
                        null,
                        $day->toDateString(),
                        'Nenhuma batida importada em '.$day->format('d/m/Y').' (dia útil da cauda da competência '.$tail['origin']->competence->format('m/Y').'). Compensações de ausência não são sugeridas para esta data: verifique a importação, o relógio ou o cadastro de feriados.',
                        coverage: $this->tailCoverageState($tail, $importCoverage),
                    );
                }
            }
        }

        if ($previousTail !== null && $previousTail['status'] === 'processable') {
            $findings = $this->tailEvents($previousTail, $analysis, $tracked->whereIn('id', $previousTailCompensableIds), $previousTail['origin']);

            foreach ($findings['events'] as $event) {
                if ($event['state'] === 'new') {
                    $conflicts[] = $this->uncompensatedTailConflict($event, $previousTail, $origin, $importCoverage);
                }
            }
        }

        foreach ($analysis['days'] as $date => $dayType) {
            if ($processedTo === null || $date > $processedTo) {
                break;
            }

            if ($dayType === CalendarDayType::BusinessDay && ! isset($analysis['imported_dates'][$date])) {
                $conflicts[] = $this->conflict(
                    'day_without_punches',
                    null,
                    $date,
                    'Nenhuma batida importada em '.CarbonImmutable::parse($date)->format('d/m/Y').' (dia útil). Ausências não são sugeridas para esta data: verifique a importação, o relógio ou o cadastro de feriados.',
                    coverage: $this->coverageState($date, $importCoverage, false),
                );
            }
        }

        foreach ($analysis['unknown_pis'] as $pis => $dates) {
            $conflicts[] = $this->conflict(
                'unknown_pis',
                null,
                null,
                'PIS '.$pis.' tem batidas na janela ('.count($dates).' dia(s)) sem funcionário cadastrado. As batidas foram ignoradas.',
            );
        }

        foreach ($analysis['duplicated_pis'] as $pis => $employees) {
            $conflicts[] = $this->conflict(
                'duplicated_pis',
                null,
                null,
                'PIS '.$pis.' pertence a mais de um participante ('.$employees->pluck('name')->join(', ').'). A geração fica bloqueada até a correção do cadastro.',
            );
        }

        return $conflicts;
    }

    /**
     * Normaliza um PIS para comparação: somente dígitos, sem zeros à esquerda.
     */
    public function normalizePis(mixed $pis): string
    {
        return ltrim(preg_replace('/\D/', '', (string) $pis) ?? '', '0');
    }

    /**
     * Carrega, com consultas fixas, tudo que a geração e os conflitos precisam.
     * `tracked` = participantes mais os funcionários de `$extraEmployeeIds` (com
     * sugestões do ponto na competência), que recebem batidas e cobertura, mas não
     * entram na geração nem na verificação de PIS duplicado.
     *
     * `$from` amplia para trás o intervalo de batidas e de cobertura (cauda da
     * competência anterior e datas de compensações); `days` continua sendo a janela da
     * competência e os dias anteriores ficam em `earlier_days`. PIS desconhecidos são
     * contados somente na janela.
     *
     * @param  list<int>  $extraEmployeeIds
     * @return array{
     *     days: Collection<string, CalendarDayType>,
     *     earlier_days: Collection<string, CalendarDayType>,
     *     employees: Collection<int, Employee>,
     *     tracked: Collection<int, Employee>,
     *     punched: array<int, array<string, true>>,
     *     covered_absence: array<int, array<string, list<BenefitAdjustment>>>,
     *     covered_work: array<int, array<string, list<BenefitAdjustment>>>,
     *     imported_dates: array<string, true>,
     *     unknown_pis: array<string, array<string, true>>,
     *     duplicated_pis: array<string, Collection<int, Employee>>
     * }
     */
    private function analyze(BenefitPeriod $period, array $extraEmployeeIds = [], ?CarbonImmutable $from = null): array
    {
        $windowStart = $period->windowStart();
        $windowEnd = $period->windowEnd();
        $rangeStart = $from !== null && $from->lessThan($windowStart) ? $from : $windowStart;

        $participantIds = $this->eligibility->participants($period->referenceDate())->keys();

        $allEmployees = Employee::query()->orderBy('name')->get(['id', 'pis', 'name', 'recision_date']);
        $employees = $allEmployees->whereIn('id', $participantIds)->values();
        $tracked = $allEmployees->whereIn('id', $participantIds->merge($extraEmployeeIds)->unique())->values();

        $knownPis = $allEmployees
            ->map(fn (Employee $employee) => $this->normalizePis($employee->pis))
            ->filter()
            ->flip();

        $participantsByPis = $employees
            ->groupBy(fn (Employee $employee) => $this->normalizePis($employee->pis))
            ->forget('');

        $duplicatedPis = $participantsByPis->filter(fn (Collection $group) => $group->count() > 1)->all();

        $trackedByPis = $tracked
            ->groupBy(fn (Employee $employee) => $this->normalizePis($employee->pis))
            ->forget('');

        $punched = [];
        $importedDates = [];
        $unknownPis = [];

        $points = Point::query()
            ->toBase()
            ->select(['pis', 'date', 'type'])
            ->distinct()
            ->where('date', '>=', $rangeStart->toDateString())
            ->where('date', '<', $windowEnd->addDay()->toDateString())
            ->get();

        foreach ($points as $point) {
            $pis = $this->normalizePis($point->pis);
            $date = substr((string) $point->date, 0, 10);

            if ($point->type === 'importado') {
                $importedDates[$date] = true;
            }

            if ($pis === '') {
                continue;
            }

            if (! $knownPis->has($pis)) {
                if ($date >= $windowStart->toDateString()) {
                    $unknownPis[$pis][$date] = true;
                }

                continue;
            }

            foreach ($trackedByPis->get($pis, collect()) as $employee) {
                $punched[$employee->id][$date] = true;
            }
        }

        [$coveredAbsence, $coveredWork] = $this->coverage($tracked->modelKeys(), $rangeStart, $windowEnd);

        return [
            'days' => $this->calendar->days($windowStart, $windowEnd),
            'earlier_days' => $rangeStart->lessThan($windowStart) ? $this->calendar->days($rangeStart, $windowStart->subDay()) : collect(),
            'employees' => $employees,
            'tracked' => $tracked,
            'punched' => $punched,
            'covered_absence' => $coveredAbsence,
            'covered_work' => $coveredWork,
            'imported_dates' => $importedDates,
            'unknown_pis' => $unknownPis,
            'duplicated_pis' => $duplicatedPis,
        ];
    }

    /**
     * DD4: dias da janela cobertos por ajustes não rejeitados de ausência ou de
     * trabalho, em qualquer competência (inclui eventos previstos da competência anterior).
     * Cada dia guarda os ajustes que o cobrem, usados também para identificar o ajuste
     * afetado nos conflitos.
     *
     * @param  list<int>  $employeeIds
     * @return array{0: array<int, array<string, list<BenefitAdjustment>>>, 1: array<int, array<string, list<BenefitAdjustment>>>}
     */
    private function coverage(array $employeeIds, CarbonImmutable $windowStart, CarbonImmutable $windowEnd): array
    {
        $coveredAbsence = [];
        $coveredWork = [];

        if ($employeeIds === []) {
            return [$coveredAbsence, $coveredWork];
        }

        $adjustments = BenefitAdjustment::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('status', '!=', AdjustmentStatus::Rejected)
            ->where('starts_on', '<=', $windowEnd->toDateString())
            ->where('ends_on', '>=', $windowStart->toDateString())
            ->orderBy('id')
            ->get(['id', 'benefit_period_id', 'employee_id', 'reason', 'status', 'source', 'starts_on', 'ends_on']);

        foreach ($adjustments as $adjustment) {
            if (! $adjustment->reason->isAbsence() && ! $adjustment->reason->isWork()) {
                continue;
            }

            $start = $adjustment->starts_on->max($windowStart);
            $end = $adjustment->ends_on->min($windowEnd);

            for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
                if ($adjustment->reason->isAbsence()) {
                    $coveredAbsence[(int) $adjustment->employee_id][$day->toDateString()][] = $adjustment;
                } else {
                    $coveredWork[(int) $adjustment->employee_id][$day->toDateString()][] = $adjustment;
                }
            }
        }

        return [$coveredAbsence, $coveredWork];
    }

    /**
     * Conflito de uma sugestão do ponto não rejeitada em um dia (C2–C5), ou null. A
     * ausência com batida de uma sugestão comum é tratada em absence_with_punch (C1) e
     * não se repete aqui; a de uma compensação, cuja data fica fora da janela da página,
     * é emitida aqui. Para compensações o ponto coberto é o horizonte e a execução que
     * processou a data é a que registrou a cauda (`tail_from`–`tail_to`).
     *
     * @param  array<string, mixed>  $analysis
     * @param  array{last_imported: ?CarbonImmutable, horizon: ?CarbonImmutable, from: ?CarbonImmutable, to: ?CarbonImmutable}  $importCoverage
     * @param  Collection<int, BenefitSuggestionRun>  $runs  execuções da competência
     * @param  bool  $createdUnderObservedRule  ficou demonstrado que a sugestão foi criada por uma execução registrada, que só sugere ausência em dia com batidas importadas
     * @param  BenefitPeriod|null  $compensationOrigin  competência de origem, quando o ajuste é uma compensação
     * @return array{type: string, employee_id: ?int, date: ?string, message: string, adjustment_id: ?int, coverage: ?string, action: ?string}|null
     */
    private function timesheetConflict(
        BenefitAdjustment $adjustment,
        Employee $employee,
        string $date,
        array $analysis,
        array $importCoverage,
        Collection $runs,
        bool $createdUnderObservedRule,
        BenefitPeriod $period,
        ?BenefitPeriod $compensationOrigin = null,
    ): ?array {
        $isCompensation = $this->registrar->isCompensation($adjustment);
        $punched = isset($analysis['punched'][$employee->id][$date]);
        $observed = isset($analysis['imported_dates'][$date]);
        $limit = $isCompensation ? $importCoverage['horizon'] : $importCoverage['to'];
        $withinCoverage = $limit !== null && $date <= $limit->toDateString();
        $dayType = $analysis['days']->get($date) ?? $analysis['earlier_days']->get($date);
        $isBusinessDay = $dayType === CalendarDayType::BusinessDay;
        $unchanged = $this->matchesSuggestionKey($adjustment, $date);

        if (! $adjustment->reason->isAbsence() && ! $adjustment->reason->isWork()) {
            return null;
        }

        $subject = $employee->name.': '.$this->describeAdjustment($adjustment, $date, $period, $compensationOrigin);
        $edited = $unchanged ? '' : ' O ajuste foi alterado na revisão depois de sugerido.';
        $coverage = $this->coverageState($date, $importCoverage, $observed, $isCompensation);
        $action = $this->recommendedAction($adjustment, $period);

        if ($punched) {
            if (! $isCompensation || ! $adjustment->reason->isAbsence() || $dayType === null) {
                return null;
            }

            $formattedDate = CarbonImmutable::parse($date)->format('d/m/Y');

            return $isBusinessDay
                ? $this->conflict('absence_with_punch', $employee->id, $date, $employee->name.' teve uma ausência registrada para o dia '.$formattedDate.', mas existe batida no ponto. Ajuste afetado: '.$this->describeAdjustment($adjustment, $date, $period, $compensationOrigin).'.', $adjustment->id, $coverage, $action)
                : $this->conflict('work_on_absence', $employee->id, $date, $employee->name.' tem batida em '.$formattedDate.' ('.$this->dayTypeLabel($dayType).') durante uma ausência registrada. Ajuste afetado: '.$this->describeAdjustment($adjustment, $date, $period, $compensationOrigin).'.', $adjustment->id, $coverage, $action);
        }

        if (! $withinCoverage) {
            if ($isCompensation) {
                $current = $importCoverage['horizon'] === null
                    ? 'não há ponto importado'
                    : 'o ponto atual é considerado completo somente até '.$importCoverage['horizon']->format('d/m/Y');
                $processingRun = $runs->first(fn (BenefitSuggestionRun $run) => $run->tail_from !== null && $run->tail_to !== null
                    && $run->tail_from->toDateString() <= $date && $run->tail_to->toDateString() >= $date);
                $processedUntil = $processingRun?->tail_to;
            } else {
                $current = $limit === null
                    ? 'nenhum dia da janela está coberto pelo ponto atual'
                    : 'o ponto atual cobre a janela somente até '.$limit->format('d/m/Y');
                $bestRun = $runs->sortByDesc(fn (BenefitSuggestionRun $run) => $run->processed_to->toDateString().sprintf('%020d', $run->id))->first();
                $processingRun = $bestRun !== null && $bestRun->processed_to->toDateString() >= $date ? $bestRun : null;
                $processedUntil = $processingRun?->processed_to;
            }

            if ($processingRun !== null) {
                return $this->conflict(
                    'suggestion_beyond_coverage',
                    $employee->id,
                    $date,
                    $subject.' foi sugerido com o ponto coberto até '.$processedUntil->format('d/m/Y')
                        .' (execução de '.$processingRun->created_at->format('d/m/Y H:i').', horizonte '.$processingRun->horizon_on->format('d/m/Y').'), mas '.$current
                        .'. A cobertura recuou: revise a sugestão, pois a evidência atual do ponto não alcança a data.'.$edited,
                    $adjustment->id,
                    $coverage,
                    $action,
                );
            }

            return $this->conflict(
                'suggestion_coverage_unknown',
                $employee->id,
                $date,
                $subject.' está fora do ponto coberto ('.$current.') e não há execução registrada que tenha processado esta data: a cobertura anterior é desconhecida (sugestões anteriores ao registro de execuções não têm histórico). Revise a sugestão.'.$edited,
                $adjustment->id,
                $coverage,
                $action,
            );
        }

        if ($adjustment->reason->isWork()) {
            return $this->conflict(
                'work_without_punch',
                $employee->id,
                $date,
                $subject.($observed
                    ? ' foi sugerido pelo ponto, mas não há mais batida do funcionário nesta data, embora o dia tenha outras batidas importadas: a evidência que gerou a sugestão deixou de existir.'
                    : ' foi sugerido pelo ponto, mas não há mais nenhuma batida importada nesta data: a batida pode ter sido removida ou a importação estar incompleta. Isso não prova que não houve trabalho.').$edited,
                $adjustment->id,
                $coverage,
                $action,
            );
        }

        if ($isBusinessDay && ! $observed && $unchanged && $createdUnderObservedRule) {
            return $this->conflict(
                'absence_without_imported_punches',
                $employee->id,
                $date,
                $subject.' foi sugerida quando a data tinha batidas importadas, mas hoje não há nenhuma batida importada nesta data: a base da sugestão mudou. Isso não prova que o funcionário esteve presente.',
                $adjustment->id,
                $coverage,
                $action,
            );
        }

        return null;
    }

    /**
     * Eventos da cauda (mesma classificação da geração, R34 incluída), por funcionário e
     * dia, com o estado em relação aos ajustes existentes: `existing` (chave de
     * compensação em qualquer status), `covered` (DD4: ajuste não rejeitado do mesmo tipo
     * em qualquer competência) ou `new`.
     *
     * @param  array{from: CarbonImmutable, to: CarbonImmutable}|array<string, mixed>  $tail
     * @param  array<string, mixed>  $analysis  com intervalo que inclui a cauda
     * @param  Collection<int, Employee>  $employees
     * @return array{events: list<array{employee: Employee, date: string, reason: AdjustmentReason, state: string}>, unobserved_days: int}
     */
    private function tailEvents(array $tail, array $analysis, Collection $employees, BenefitPeriod $origin): array
    {
        $existingKeys = BenefitAdjustment::query()
            ->where('dedupe_key', 'like', BenefitAdjustmentRegistrar::COMPENSATION_KEY_PREFIX.$origin->id.':%')
            ->pluck('dedupe_key')
            ->flip();

        $events = [];
        $unobservedDays = 0;

        for ($day = $tail['from']; $day->lessThanOrEqualTo($tail['to']); $day = $day->addDay()) {
            $date = $day->toDateString();
            $dayType = $analysis['earlier_days']->get($date) ?? $analysis['days']->get($date) ?? $this->calendar->classify($day);

            if ($dayType === CalendarDayType::BusinessDay && ! isset($analysis['imported_dates'][$date])) {
                $unobservedDays++;

                continue;
            }

            foreach ($employees as $employee) {
                $reason = $this->suggestedReason($dayType, isset($analysis['punched'][$employee->id][$date]));

                if ($reason === null) {
                    continue;
                }

                $coverage = $reason->isAbsence() ? $analysis['covered_absence'] : $analysis['covered_work'];

                $events[] = [
                    'employee' => $employee,
                    'date' => $date,
                    'reason' => $reason,
                    'state' => match (true) {
                        $existingKeys->has($this->registrar->compensationKey($origin, $employee, $reason, $day)) => 'existing',
                        isset($coverage[$employee->id][$date]) => 'covered',
                        default => 'new',
                    },
                ];
            }
        }

        return ['events' => $events, 'unobserved_days' => $unobservedDays];
    }

    /**
     * Observação automática da compensação: somente fatos registrados.
     *
     * @param  array<string, mixed>  $tail
     * @param  array{last_imported: ?CarbonImmutable, horizon: ?CarbonImmutable, from: ?CarbonImmutable, to: ?CarbonImmutable}  $importCoverage
     */
    private function compensationNotes(array $tail, CarbonImmutable $day, array $importCoverage): string
    {
        return 'Compensação da competência '.$tail['origin']->competence->format('m/Y').': evento de '.$day->format('d/m/Y')
            .' posterior ao ponto apurado naquela competência (cobertura registrada até '.$tail['covered_until']->format('d/m/Y')
            .' pela execução #'.$tail['origin_run']->id.' de '.$tail['origin_run']->created_at->format('d/m/Y H:i').').'
            .' Sugerido com o ponto considerado completo até '.$importCoverage['horizon']->format('d/m/Y').'.';
    }

    /**
     * C6: evento da cauda que não pode ser compensado porque o destino está fechado.
     *
     * @param  array{employee: Employee, date: string, reason: AdjustmentReason, state: string}  $event
     * @param  array<string, mixed>  $tail
     * @param  array{last_imported: ?CarbonImmutable, horizon: ?CarbonImmutable, from: ?CarbonImmutable, to: ?CarbonImmutable}  $importCoverage
     * @return array{type: string, employee_id: ?int, date: ?string, message: string, adjustment_id: ?int, coverage: ?string, action: ?string}
     */
    private function uncompensatedTailConflict(array $event, array $tail, BenefitPeriod $destination, array $importCoverage): array
    {
        return $this->conflict(
            'uncompensated_tail',
            $event['employee']->id,
            $event['date'],
            $event['employee']->name.': evento de '.CarbonImmutable::parse($event['date'])->format('d/m/Y').' ('.$event['reason']->label().') está na cauda da competência '
                .$tail['origin']->competence->format('m/Y').' (cobertura registrada até '.$tail['covered_until']->format('d/m/Y').' pela execução #'.$tail['origin_run']->id
                .') e não foi compensado: a competência de destino '.$destination->competence->format('m/Y').' está fechada e não recebe lançamentos automáticos.',
            coverage: $this->tailCoverageState($tail, $importCoverage),
            action: 'Se o evento proceder, lance um ajuste Manual retroativo na próxima competência editável. Não reabra competências apenas por isso.',
        );
    }

    /**
     * @param  array<string, mixed>  $tail
     * @param  array{last_imported: ?CarbonImmutable, horizon: ?CarbonImmutable, from: ?CarbonImmutable, to: ?CarbonImmutable}  $importCoverage
     */
    private function tailCoverageState(array $tail, array $importCoverage): string
    {
        return 'Cauda da competência '.$tail['origin']->competence->format('m/Y').': '.$tail['from']->format('d/m/Y').' a '.$tail['window_end']->format('d/m/Y')
            .'; ponto considerado completo até '.$importCoverage['horizon']->format('d/m/Y').'.';
    }

    /**
     * Id da competência de origem gravado na chave de uma compensação.
     */
    private function compensationOriginId(BenefitAdjustment $adjustment): ?int
    {
        $parts = explode(':', (string) $adjustment->dedupe_key);

        return $this->registrar->isCompensation($adjustment) && isset($parts[1]) ? (int) $parts[1] : null;
    }

    /**
     * Demonstra que toda sugestão do ponto da competência foi criada por uma execução
     * registrada: cada execução conta em `created` exatamente as sugestões que gravou, na
     * mesma transação, e ajustes não são excluídos pelo sistema. Se a quantidade de
     * sugestões (em qualquer status) for igual à soma de `created`, nenhuma veio de outro
     * caminho (geração anterior ao registro de execuções ou chamada direta ao registrar).
     * O contador não identifica qual ajuste cada execução criou, então qualquer diferença
     * torna a origem de todas indemonstrável.
     *
     * @param  Collection<int, BenefitAdjustment>  $timesheetAdjustments  todas as sugestões do ponto da competência, inclusive rejeitadas
     * @param  Collection<int, BenefitSuggestionRun>  $runs
     */
    private function allTimesheetCreatedByRuns(Collection $timesheetAdjustments, Collection $runs): bool
    {
        return $runs->isNotEmpty()
            && $timesheetAdjustments->isNotEmpty()
            && $timesheetAdjustments->count() === (int) $runs->sum('created');
    }

    /**
     * A sugestão mantém a data e o motivo com que foi criada (gravados na dedupe_key).
     */
    private function matchesSuggestionKey(BenefitAdjustment $adjustment, string $date): bool
    {
        $parts = explode(':', (string) $adjustment->dedupe_key);

        return count($parts) === 5
            && $parts[3] === $date
            && $parts[4] === $adjustment->reason->name
            && $adjustment->starts_on->equalTo($adjustment->ends_on);
    }

    /**
     * @param  array{last_imported: ?CarbonImmutable, horizon: ?CarbonImmutable, from: ?CarbonImmutable, to: ?CarbonImmutable}  $importCoverage
     */
    private function coverageState(string $date, array $importCoverage, bool $observed, bool $compensation = false): string
    {
        if ($importCoverage['horizon'] === null) {
            return 'Nenhuma batida importada.';
        }

        $horizon = $importCoverage['horizon']->format('d/m/Y');
        $limit = $compensation ? $importCoverage['horizon'] : $importCoverage['to'];

        if ($limit === null || $date > $limit->toDateString()) {
            return 'Data após o ponto coberto (ponto considerado completo até '.$horizon.').';
        }

        return $observed
            ? 'Ponto coberto até '.$horizon.'; a data tem batidas importadas.'
            : 'Ponto coberto até '.$horizon.', mas nenhuma batida importada nesta data.';
    }

    /**
     * Ação de revisão manual recomendada. Nenhuma ação é executada automaticamente.
     */
    private function recommendedAction(BenefitAdjustment $adjustment, ?BenefitPeriod $period): string
    {
        if ($period?->status === BenefitPeriodStatus::Closed) {
            return 'Competência '.$period->competence->format('m/Y').' fechada: se a correção proceder, lance um ajuste Manual retroativo na competência seguinte. Não reabra a competência apenas por isso.';
        }

        return $adjustment->status === AdjustmentStatus::Confirmed
            ? 'Revise o ajuste #'.$adjustment->id.': rejeite ou substitua.'
            : 'Revise o ajuste #'.$adjustment->id.': rejeite com nota ou altere o motivo.';
    }

    private function describeAdjustment(BenefitAdjustment $adjustment, string $date, ?BenefitPeriod $period, ?BenefitPeriod $compensationOrigin = null): string
    {
        return $adjustment->reason->label().' em '.CarbonImmutable::parse($date)->format('d/m/Y')
            .' (#'.$adjustment->id.', '.mb_strtolower($adjustment->status->label())
            .($period !== null ? ', competência '.$period->competence->format('m/Y') : '')
            .($compensationOrigin !== null ? ', compensação da competência '.$compensationOrigin->competence->format('m/Y') : '').')';
    }

    /**
     * @return array{type: string, employee_id: ?int, date: ?string, message: string, adjustment_id: ?int, coverage: ?string, action: ?string}
     */
    private function conflict(string $type, ?int $employeeId, ?string $date, string $message, ?int $adjustmentId = null, ?string $coverage = null, ?string $action = null): array
    {
        return [
            'type' => $type,
            'employee_id' => $employeeId,
            'date' => $date,
            'message' => $message,
            'adjustment_id' => $adjustmentId,
            'coverage' => $coverage,
            'action' => $action,
        ];
    }

    /**
     * Classificação do dia (Spec §13.3). Feriado tem precedência sobre sábado/domingo
     * pela própria classificação do BusinessCalendar (R8).
     */
    private function suggestedReason(CalendarDayType $dayType, bool $punched): ?AdjustmentReason
    {
        return match ($dayType) {
            CalendarDayType::BusinessDay => $punched ? null : AdjustmentReason::UnjustifiedAbsence,
            CalendarDayType::Saturday => $punched ? AdjustmentReason::SaturdayWorked : null,
            CalendarDayType::Sunday => $punched ? AdjustmentReason::SundayWorked : null,
            CalendarDayType::Holiday => $punched ? AdjustmentReason::HolidayWorked : null,
        };
    }

    private function dayTypeLabel(CalendarDayType $dayType): string
    {
        return match ($dayType) {
            CalendarDayType::Saturday => 'sábado',
            CalendarDayType::Sunday => 'domingo',
            CalendarDayType::Holiday => 'feriado',
            CalendarDayType::BusinessDay => 'dia útil',
        };
    }

    private function formatDate(string $date): string
    {
        try {
            return CarbonImmutable::parse($date)->format('d/m/Y');
        } catch (\Throwable) {
            return $date;
        }
    }
}
