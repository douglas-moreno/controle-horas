<?php

namespace App\Services;

use App\Enums\AdjustmentReason;
use App\Enums\AdjustmentStatus;
use App\Enums\CalendarDayType;
use App\Models\BenefitAdjustment;
use App\Models\BenefitPeriod;
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
     * Motivo pelo qual a geração não pode rodar, ou null quando pode.
     */
    public function generationBlocker(BenefitPeriod $period): ?string
    {
        if (! $period->status->isEditable()) {
            return 'A competência '.$period->competence->format('m/Y').' está fechada: sugestões não podem ser geradas.';
        }

        $lastImported = $this->lastImportedPointDate();

        if ($lastImported === null || $lastImported->lessThan($period->windowEnd())) {
            return 'Batidas importadas até '.($lastImported?->format('d/m/Y') ?? 'nenhuma data').'. '
                .'A geração exige batidas até '.$period->windowEnd()->format('d/m/Y').'.';
        }

        return null;
    }

    /**
     * Gera as sugestões da competência de forma idempotente (DD1 + DD4).
     *
     * @return array{created: int, skipped_existing: int, skipped_covered: int}
     *
     * @throws DomainException quando a geração está bloqueada ou uma sugestão viola as regras do registro
     */
    public function generate(BenefitPeriod $period): array
    {
        return DB::transaction(function () use ($period) {
            $period = BenefitPeriod::query()->lockForUpdate()->findOrFail($period->id);

            $blocker = $this->generationBlocker($period);

            if ($blocker !== null) {
                throw new DomainException($blocker);
            }

            $analysis = $this->analyze($period);

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

            $summary = ['created' => 0, 'skipped_existing' => 0, 'skipped_covered' => 0];

            foreach ($analysis['days'] as $date => $dayType) {
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

            return $summary;
        });
    }

    /**
     * Conflitos calculados sob demanda para a revisão (não persistidos).
     *
     * @return list<array{type: string, employee_id: ?int, date: ?string, message: string}>
     */
    public function conflicts(BenefitPeriod $period): array
    {
        $analysis = $this->analyze($period);
        $conflicts = [];

        foreach ($analysis['employees'] as $employee) {
            $recisionDate = trim((string) $employee->recision_date);

            if ($recisionDate !== '') {
                $conflicts[] = [
                    'type' => 'employee_rescinded',
                    'employee_id' => $employee->id,
                    'date' => null,
                    'message' => $employee->name.' possui data de rescisão ('.$this->formatDate($recisionDate).') e continua participando pela vigência de benefício. Encerre as vigências se o funcionário não deve receber.',
                ];
            }
        }

        foreach ($analysis['days'] as $date => $dayType) {
            foreach ($analysis['employees'] as $employee) {
                if (! isset($analysis['punched'][$employee->id][$date], $analysis['covered_absence'][$employee->id][$date])) {
                    continue;
                }

                $formattedDate = CarbonImmutable::parse($date)->format('d/m/Y');

                $conflicts[] = $dayType === CalendarDayType::BusinessDay
                    ? [
                        'type' => 'absence_with_punch',
                        'employee_id' => $employee->id,
                        'date' => $date,
                        'message' => $employee->name.' teve uma ausência registrada para o dia '.$formattedDate.', mas existe batida no ponto.',
                    ]
                    : [
                        'type' => 'work_on_absence',
                        'employee_id' => $employee->id,
                        'date' => $date,
                        'message' => $employee->name.' tem batida em '.$formattedDate.' ('.$this->dayTypeLabel($dayType).') durante uma ausência registrada.',
                    ];
            }
        }

        foreach ($analysis['unknown_pis'] as $pis => $dates) {
            $conflicts[] = [
                'type' => 'unknown_pis',
                'employee_id' => null,
                'date' => null,
                'message' => 'PIS '.$pis.' tem batidas na janela ('.count($dates).' dia(s)) sem funcionário cadastrado. As batidas foram ignoradas.',
            ];
        }

        foreach ($analysis['duplicated_pis'] as $pis => $employees) {
            $conflicts[] = [
                'type' => 'duplicated_pis',
                'employee_id' => null,
                'date' => null,
                'message' => 'PIS '.$pis.' pertence a mais de um participante ('.$employees->pluck('name')->join(', ').'). A geração fica bloqueada até a correção do cadastro.',
            ];
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
     *
     * @return array{
     *     days: Collection<string, CalendarDayType>,
     *     employees: Collection<int, Employee>,
     *     punched: array<int, array<string, true>>,
     *     covered_absence: array<int, array<string, true>>,
     *     covered_work: array<int, array<string, true>>,
     *     unknown_pis: array<string, array<string, true>>,
     *     duplicated_pis: array<string, Collection<int, Employee>>
     * }
     */
    private function analyze(BenefitPeriod $period): array
    {
        $windowStart = $period->windowStart();
        $windowEnd = $period->windowEnd();

        $participantIds = $this->eligibility->participants($period->referenceDate())->keys();

        $allEmployees = Employee::query()->orderBy('name')->get(['id', 'pis', 'name', 'recision_date']);
        $employees = $allEmployees->whereIn('id', $participantIds)->values();

        $knownPis = $allEmployees
            ->map(fn (Employee $employee) => $this->normalizePis($employee->pis))
            ->filter()
            ->flip();

        $participantsByPis = $employees
            ->groupBy(fn (Employee $employee) => $this->normalizePis($employee->pis))
            ->forget('');

        $duplicatedPis = $participantsByPis->filter(fn (Collection $group) => $group->count() > 1)->all();

        $punched = [];
        $unknownPis = [];

        $points = Point::query()
            ->toBase()
            ->select(['pis', 'date'])
            ->distinct()
            ->where('date', '>=', $windowStart->toDateString())
            ->where('date', '<', $windowEnd->addDay()->toDateString())
            ->get();

        foreach ($points as $point) {
            $pis = $this->normalizePis($point->pis);
            $date = substr((string) $point->date, 0, 10);

            if ($pis === '') {
                continue;
            }

            if (! $knownPis->has($pis)) {
                $unknownPis[$pis][$date] = true;

                continue;
            }

            foreach ($participantsByPis->get($pis, collect()) as $employee) {
                $punched[$employee->id][$date] = true;
            }
        }

        [$coveredAbsence, $coveredWork] = $this->coverage($employees->modelKeys(), $windowStart, $windowEnd);

        return [
            'days' => $this->calendar->days($windowStart, $windowEnd),
            'employees' => $employees,
            'punched' => $punched,
            'covered_absence' => $coveredAbsence,
            'covered_work' => $coveredWork,
            'unknown_pis' => $unknownPis,
            'duplicated_pis' => $duplicatedPis,
        ];
    }

    /**
     * DD4: dias da janela cobertos por ajustes não rejeitados de ausência ou de
     * trabalho, em qualquer competência (inclui eventos previstos da competência anterior).
     *
     * @param  list<int>  $employeeIds
     * @return array{0: array<int, array<string, true>>, 1: array<int, array<string, true>>}
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
            ->get(['employee_id', 'reason', 'starts_on', 'ends_on']);

        foreach ($adjustments as $adjustment) {
            if (! $adjustment->reason->isAbsence() && ! $adjustment->reason->isWork()) {
                continue;
            }

            $start = $adjustment->starts_on->max($windowStart);
            $end = $adjustment->ends_on->min($windowEnd);

            for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
                if ($adjustment->reason->isAbsence()) {
                    $coveredAbsence[(int) $adjustment->employee_id][$day->toDateString()] = true;
                } else {
                    $coveredWork[(int) $adjustment->employee_id][$day->toDateString()] = true;
                }
            }
        }

        return [$coveredAbsence, $coveredWork];
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
