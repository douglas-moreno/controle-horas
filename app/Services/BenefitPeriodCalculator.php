<?php

namespace App\Services;

use App\Enums\AdjustmentStatus;
use App\Enums\BenefitType;
use App\Models\BenefitAdjustmentImpact;
use App\Models\BenefitCalculation;
use App\Models\BenefitCalculationTransportItem;
use App\Models\BenefitPeriod;
use App\Models\BenefitPeriodEmployee;
use App\Models\BenefitRate;
use App\Models\Employee;
use DomainException;
use Illuminate\Support\Collection;

/**
 * Apuração da competência (Spec §6 e §7) e escritor único do snapshot:
 * benefit_period_employees → benefit_calculations → benefit_calculation_transport_items.
 *
 * Por tipo elegível em 01/M: raw = base + positivos − negativos − herdado;
 * final = max(raw, 0); gerado = max(−raw, 0). Valores em centavos inteiros.
 *
 * Deve ser chamado pelo BenefitPeriodWorkflow, dentro da transação e com a
 * competência bloqueada. Não muda status, não cria ajustes e não lê o ponto.
 *
 * Issues: array{severity: 'blocking'|'warning', employee_id: ?int, benefit_type: ?string, message: string}.
 * VR/VD sem valor vigente impede o cálculo (exceção, nada é gravado); VT sem
 * itinerário ou com trecho sem preço é gravado na prévia e retornado como
 * issue bloqueante para o fechamento.
 */
class BenefitPeriodCalculator
{
    public function __construct(
        private BusinessCalendar $calendar,
        private BenefitEligibility $eligibility,
        private TransportDailyAmount $transportDailyAmount,
        private BenefitRateResolver $rateResolver,
    ) {}

    /**
     * Descarta o snapshot anterior e grava o novo.
     *
     * @return array{issues: list<array{severity: string, employee_id: ?int, benefit_type: ?string, message: string}>}
     *
     * @throws DomainException quando falta valor VR/VD vigente ou o snapshot atual já foi usado pela competência seguinte
     */
    public function calculate(BenefitPeriod $period): array
    {
        $data = $this->prepare($period);

        $rateIssues = array_values(array_filter($data['issues'], fn (array $issue) => $issue['benefit_type'] !== BenefitType::Vt->value && $issue['severity'] === 'blocking'));

        if ($rateIssues !== []) {
            throw new DomainException('Cálculo bloqueado. '.implode(' ', array_column($rateIssues, 'message')));
        }

        $this->discardSnapshot($period);

        $baseDays = $data['base_days'];
        $now = now();

        $period->business_days = $baseDays;
        $period->save();

        BenefitPeriodEmployee::query()->insert($data['employees']->map(fn (Employee $employee) => [
            'benefit_period_id' => $period->id,
            'employee_id' => $employee->id,
            'employee_name' => $employee->name,
            'pis' => (string) $employee->pis,
            'position' => (string) $employee->position,
            'created_at' => $now,
            'updated_at' => $now,
        ])->values()->all());

        $periodEmployeeIds = BenefitPeriodEmployee::query()
            ->where('benefit_period_id', $period->id)
            ->pluck('id', 'employee_id');

        $calculations = [];
        $transportItems = [];

        foreach ($data['employees'] as $employee) {
            foreach ($data['participants']->get($employee->id, []) as $benefitType) {
                $impacts = $data['impacts'][$employee->id][$benefitType->value] ?? ['positive' => 0, 'negative' => 0];
                $carriedFrom = $data['carried'][$employee->id][$benefitType->value] ?? null;
                $carriedInDays = $carriedFrom?->carried_out_days ?? 0;

                $rawDays = $baseDays + $impacts['positive'] - $impacts['negative'] - $carriedInDays;
                $finalDays = max($rawDays, 0);

                [$unitCents, $rate] = $benefitType === BenefitType::Vt
                    ? [$data['transport'][$employee->id]['daily_cents'], null]
                    : [Money::toCents($data['rates'][$benefitType->value]->amount), $data['rates'][$benefitType->value]];

                $calculations[] = [
                    'benefit_period_employee_id' => $periodEmployeeIds[$employee->id],
                    'benefit_type' => $benefitType->value,
                    'base_days' => $baseDays,
                    'positive_days' => $impacts['positive'],
                    'negative_days' => $impacts['negative'],
                    'carried_in_days' => $carriedInDays,
                    'carried_from_calculation_id' => $carriedFrom?->id,
                    'raw_days' => $rawDays,
                    'final_days' => $finalDays,
                    'carried_out_days' => max(-$rawDays, 0),
                    'unit_amount' => Money::fromCents($unitCents),
                    'total_amount' => Money::fromCents($finalDays * $unitCents),
                    'benefit_rate_id' => $rate?->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if ($benefitType === BenefitType::Vt) {
                    $transportItems[$employee->id] = $data['transport'][$employee->id]['items'];
                }
            }
        }

        foreach (array_chunk($calculations, 500) as $chunk) {
            BenefitCalculation::query()->insert($chunk);
        }

        $vtCalculationIds = BenefitCalculation::query()
            ->whereIn('benefit_period_employee_id', $periodEmployeeIds->values())
            ->where('benefit_type', BenefitType::Vt)
            ->pluck('id', 'benefit_period_employee_id');

        $itemRows = [];

        foreach ($transportItems as $employeeId => $items) {
            foreach ($items as $item) {
                $itemRows[] = [
                    'benefit_calculation_id' => $vtCalculationIds[$periodEmployeeIds[$employeeId]],
                    'transport_route_id' => $item['route_id'],
                    'transport_fare_id' => $item['fare_id'],
                    'fare_name' => $item['fare_name'],
                    'fare_amount' => Money::fromCents($item['fare_cents']),
                    'trips_per_day' => $item['trips_per_day'],
                    'daily_amount' => Money::fromCents($item['daily_cents']),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($itemRows, 500) as $chunk) {
            BenefitCalculationTransportItem::query()->insert($chunk);
        }

        return ['issues' => $data['issues']];
    }

    /**
     * Pendências de configuração da competência na situação atual, sem gravar nada.
     *
     * @return list<array{severity: string, employee_id: ?int, benefit_type: ?string, message: string}>
     */
    public function issues(BenefitPeriod $period): array
    {
        return $this->prepare($period)['issues'];
    }

    /**
     * Remove o snapshot da competência. Os cálculos e trechos saem em cascata pelo banco.
     *
     * @throws DomainException quando algum cálculo de outra competência aplicou o saldo gerado aqui
     */
    public function discardSnapshot(BenefitPeriod $period): void
    {
        $usedByNext = BenefitCalculation::query()
            ->whereIn('carried_from_calculation_id', BenefitCalculation::query()
                ->select('benefit_calculations.id')
                ->join('benefit_period_employees', 'benefit_period_employees.id', '=', 'benefit_calculations.benefit_period_employee_id')
                ->where('benefit_period_employees.benefit_period_id', $period->id))
            ->exists();

        if ($usedByNext) {
            throw new DomainException('O saldo negativo desta competência já foi aplicado na competência seguinte. Descarte o cálculo da competência seguinte antes de recalcular.');
        }

        $period->periodEmployees()->delete();
    }

    /**
     * Lê tudo o que o cálculo precisa com consultas fixas.
     *
     * @return array{
     *     base_days: int,
     *     participants: Collection<int, list<BenefitType>>,
     *     employees: Collection<int, Employee>,
     *     impacts: array<int, array<string, array{positive: int, negative: int}>>,
     *     carried: array<int, array<string, BenefitCalculation>>,
     *     rates: array<string, ?BenefitRate>,
     *     transport: Collection<int, array{daily_cents: int, items: list<array<string, mixed>>, missing_prices: list<int>}>,
     *     issues: list<array{severity: string, employee_id: ?int, benefit_type: ?string, message: string}>
     * }
     */
    private function prepare(BenefitPeriod $period): array
    {
        $reference = $period->referenceDate();
        $competence = $reference->format('m/Y');

        $participants = $this->eligibility->participants($reference);

        $employees = Employee::query()
            ->whereIn('id', $participants->keys())
            ->orderBy('name')
            ->get(['id', 'pis', 'name', 'position']);

        $issues = [];

        if (! $this->calendar->yearHasHolidays($reference->year)) {
            $issues[] = $this->issue('warning', null, null, 'Não há feriados cadastrados em '.$reference->year.': os dias-base de '.$competence.' podem estar incorretos.');
        }

        $typesInUse = $participants->flatten()->unique(fn (BenefitType $type) => $type->value);

        $rates = [];

        foreach ([BenefitType::Vr, BenefitType::Vd] as $benefitType) {
            $rates[$benefitType->value] = $this->rateResolver->forDate($benefitType, $reference);

            if ($rates[$benefitType->value] === null && $typesInUse->contains($benefitType)) {
                $issues[] = $this->issue('blocking', null, $benefitType, 'Não há valor de '.$benefitType->label().' vigente em '.$reference->format('d/m/Y').'.');
            }
        }

        $vtEmployees = $employees->filter(fn (Employee $employee) => in_array(BenefitType::Vt, $participants->get($employee->id), true));
        $transport = $this->transportDailyAmount->forEmployees($vtEmployees, $reference);

        foreach ($vtEmployees as $employee) {
            $daily = $transport->get($employee->id);

            if ($daily['items'] === [] && $daily['missing_prices'] === []) {
                $issues[] = $this->issue('blocking', $employee->id, BenefitType::Vt, $employee->name.' é elegível a VT, mas não possui itinerário vigente em '.$reference->format('d/m/Y').'.');
            }

            if ($daily['missing_prices'] !== []) {
                $issues[] = $this->issue('blocking', $employee->id, BenefitType::Vt, $employee->name.': '.count($daily['missing_prices']).' trecho(s) do itinerário sem preço vigente em '.$reference->format('d/m/Y').' (competência '.$competence.').');
            }
        }

        return [
            'base_days' => $this->calendar->businessDaysInMonth($reference),
            'participants' => $participants,
            'employees' => $employees,
            'impacts' => $this->confirmedImpacts($period),
            'carried' => $this->carriedBalances($period, $participants),
            'rates' => $rates,
            'transport' => $transport,
            'issues' => $issues,
        ];
    }

    /**
     * Soma, com uma consulta agregada, os impactos de ajustes confirmados da competência.
     *
     * @return array<int, array<string, array{positive: int, negative: int}>>
     */
    private function confirmedImpacts(BenefitPeriod $period): array
    {
        $rows = BenefitAdjustmentImpact::query()
            ->join('benefit_adjustments', 'benefit_adjustments.id', '=', 'benefit_adjustment_impacts.benefit_adjustment_id')
            ->where('benefit_adjustments.benefit_period_id', $period->id)
            ->where('benefit_adjustments.status', AdjustmentStatus::Confirmed)
            ->groupBy('benefit_adjustments.employee_id', 'benefit_adjustment_impacts.benefit_type')
            ->selectRaw('benefit_adjustments.employee_id as employee_id')
            ->selectRaw('benefit_adjustment_impacts.benefit_type as benefit_type')
            ->selectRaw('sum(case when benefit_adjustment_impacts.quantity > 0 then benefit_adjustment_impacts.quantity else 0 end) as positive_days')
            ->selectRaw('sum(case when benefit_adjustment_impacts.quantity < 0 then -benefit_adjustment_impacts.quantity else 0 end) as negative_days')
            ->toBase()
            ->get();

        $impacts = [];

        foreach ($rows as $row) {
            $impacts[(int) $row->employee_id][$row->benefit_type] = [
                'positive' => (int) $row->positive_days,
                'negative' => (int) $row->negative_days,
            ];
        }

        return $impacts;
    }

    /**
     * Saldos negativos gerados em M−1 (fechada) que se aplicam em M: mesmo funcionário,
     * mesmo tipo e elegibilidade ao tipo em 01/M. Nunca vem de competências anteriores a M−1.
     *
     * @param  Collection<int, list<BenefitType>>  $participants
     * @return array<int, array<string, BenefitCalculation>>
     */
    private function carriedBalances(BenefitPeriod $period, Collection $participants): array
    {
        $previous = BenefitPeriod::query()
            ->where('competence', $period->referenceDate()->subMonthNoOverflow()->toDateString())
            ->first();

        if ($previous === null || $previous->status->isEditable()) {
            return [];
        }

        $origins = BenefitCalculation::query()
            ->select('benefit_calculations.*', 'benefit_period_employees.employee_id')
            ->join('benefit_period_employees', 'benefit_period_employees.id', '=', 'benefit_calculations.benefit_period_employee_id')
            ->where('benefit_period_employees.benefit_period_id', $previous->id)
            ->where('benefit_calculations.carried_out_days', '>', 0)
            ->get();

        $carried = [];

        foreach ($origins as $origin) {
            $employeeId = (int) $origin->employee_id;

            if (in_array($origin->benefit_type, $participants->get($employeeId, []), true)) {
                $carried[$employeeId][$origin->benefit_type->value] = $origin;
            }
        }

        return $carried;
    }

    /**
     * @return array{severity: string, employee_id: ?int, benefit_type: ?string, message: string}
     */
    private function issue(string $severity, ?int $employeeId, ?BenefitType $benefitType, string $message): array
    {
        return [
            'severity' => $severity,
            'employee_id' => $employeeId,
            'benefit_type' => $benefitType?->value,
            'message' => $message,
        ];
    }
}
