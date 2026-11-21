<?php

namespace App\Services;

use App\Enums\TimesheetSnapshotSource;
use App\Models\AfdPointDecision;
use App\Models\BenefitPeriod;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Point;
use App\Models\PointJustification;
use App\Models\PointJustificationHistory;
use App\Models\TimesheetSnapshot;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use ReflectionClass;

/**
 * Gravação dos snapshots do espelho de ponto (F6.2a), um por funcionário e dia.
 *
 * `capture()` lê o dia como o TimesheetMirror apresenta (`result`) e as entradas do
 * cálculo (`inputs`), sem gravar. `persist()` grava o que foi capturado; `record()` faz
 * os dois. Assim o "antes" de uma alteração pode ser capturado antes dela e gravado
 * depois, já ligado ao histórico da justificativa, na mesma transação.
 *
 * Entradas (`inputs`), em duas partes:
 * - `calculation`: tudo que muda o resultado apresentado do dia — PIS do funcionário (as
 *   batidas são selecionadas por ele; trocá-lo muda a origem dos dados), tipo do dia, feriado,
 *   jornada do dia da semana, tolerância, batidas (horário e tipo), anotações do RH,
 *   justificativas ativas que atingem o dia (campos, motivo e categoria), parâmetros da
 *   regra de horas extras e da regra alternativa (somente quando ligada). `inputs_hash` é
 *   calculado só sobre esta parte: entradas equivalentes têm o mesmo hash.
 * - `references`: identificação para auditoria — funcionário e PIS, ids das batidas com
 *   REP e NSR, ids, versões e último histórico das justificativas. Não entra no hash.
 *
 * Hash: SHA-256 de um JSON canônico (chaves de objetos ordenadas, listas na ordem de
 * cálculo, enums pelo valor, sem escapar barras e Unicode), prefixado pela versão do esquema.
 *
 * Versão das regras: `rule_version` (rótulo mantido à mão) e `rules_hash` (SHA-256 do
 * código-fonte das classes de cálculo), que muda com qualquer edição desse código.
 *
 * Versionamento: `sequence` cresce por funcionário e dia, com o funcionário bloqueado na
 * transação (o índice único é a última barreira). Regras de repetição:
 * - closure_baseline, before_change, after_change: sempre gravados (cada um documenta um
 *   fechamento ou uma operação); antes/depois exigem `operation_uuid`;
 * - late_baseline: não é gravado no dia que já tem um ponto de partida (fechamento ou tardio);
 * - recalculation: não é gravado quando o último snapshot do dia tem as mesmas entradas,
 *   regras e resultado.
 *
 * Ponto de partida do fechamento (`recordClosureBaseline()`, F6.2c): todos os dias da
 * janela da competência (mês civil anterior, o mesmo critério da competência de uma data
 * nas justificativas) de cada funcionário abrangido — sem rescisão antes do início da
 * janela, ou com batida ou justificativa na janela —, inclusive dias sem marcação, fins de
 * semana e feriados. Os dias do período 26→25 do espelho que caem em outra competência não
 * entram: cada dia pertence a uma única competência.
 */
class TimesheetSnapshotRecorder
{
    public const SCHEMA_VERSION = 1;

    public const RULE_VERSION = 'timesheet-mirror/f5.4';

    /**
     * Classes cujo código entra em `rules_hash`.
     *
     * @var list<class-string>
     */
    public const RULE_SOURCES = [
        TimesheetMirror::class,
        OvertimeCalculator::class,
        AlternativeTimesheetRule::class,
        TimesheetJustifications::class,
        BusinessCalendar::class,
    ];

    private static ?string $rulesHash = null;

    public function __construct(
        private TimesheetMirror $mirror,
        private TimesheetJustifications $justifications,
        private AlternativeTimesheetRule $alternativeRule,
    ) {}

    /**
     * Captura e grava os dias, em uma transação.
     *
     * @param  list<CarbonInterface|string>  $dates
     * @param  array{benefit_period_id?: ?int, point_justification_history_id?: ?int, operation_uuid?: ?string, reason?: ?string}  $context
     * @return Collection<int, TimesheetSnapshot> snapshots gravados (dias dispensados pelas regras de repetição ficam de fora)
     */
    public function record(Employee $employee, array $dates, TimesheetSnapshotSource $source, ?User $user, array $context = []): Collection
    {
        return DB::transaction(fn () => $this->persist($employee, $this->capture($employee, $dates), $source, $user, $context));
    }

    /**
     * Dias como o espelho apresenta agora, com as entradas e os hashes. Não grava.
     *
     * @param  list<CarbonInterface|string>  $dates
     * @return array<string, array{employee_id: int, date: string, inputs: array<string, mixed>, inputs_hash: string, result: array<string, mixed>, result_hash: string, rules_hash: string}>
     */
    public function capture(Employee $employee, array $dates): array
    {
        $dates = collect($dates)
            ->map(fn (CarbonInterface|string $date) => CarbonImmutable::parse(is_string($date) ? $date : $date->toDateString())->toDateString())
            ->unique()->sort()->values();

        if ($dates->isEmpty()) {
            return [];
        }

        $start = CarbonImmutable::parse($dates->first());
        $end = CarbonImmutable::parse($dates->last());

        $points = blank($employee->pis) ? collect() : Point::query()
            ->where('pis', $employee->pis)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('date')->orderBy('time')->orderBy('id')
            ->get();
        $justificationsByDate = $this->justifications->forEmployee($employee, $start, $end);
        $mirrorDays = collect($this->mirror->build($points, $start, $end, $justificationsByDate)['days'])->keyBy('date');
        $labels = AfdPointDecision::activeLabels($points->pluck('id')->map(fn ($id) => (int) $id)->all());
        $lastHistories = $this->lastHistoryIds(collect($justificationsByDate)->flatten(1)->map(fn (PointJustification $justification) => (int) $justification->getKey())->unique()->values()->all());
        $pointsByDate = $points->groupBy(fn (Point $point) => $point->date->toDateString());

        $captured = [];

        foreach ($dates as $date) {
            $inputs = $this->inputs($employee, CarbonImmutable::parse($date), $pointsByDate->get($date, collect()), $labels, $justificationsByDate[$date] ?? [], $lastHistories, $mirrorDays[$date]['day_type']->value);
            $result = self::normalize($mirrorDays[$date]);

            $captured[$date] = [
                'employee_id' => (int) $employee->getKey(),
                'date' => $date,
                'inputs' => $inputs,
                'inputs_hash' => self::hash($inputs['calculation']),
                'result' => $result,
                'result_hash' => self::hash($result),
                'rules_hash' => self::rulesHash(),
            ];
        }

        return $captured;
    }

    /**
     * Grava dias capturados, com o funcionário bloqueado, aplicando as regras de repetição.
     *
     * @param  array<string, array{employee_id: int, date: string, inputs: array<string, mixed>, inputs_hash: string, result: array<string, mixed>, result_hash: string, rules_hash: string}>  $captured  resultado de capture()
     * @param  array{benefit_period_id?: ?int, point_justification_history_id?: ?int, operation_uuid?: ?string, reason?: ?string}  $context
     * @param  bool  $lockEmployee  false somente quando o chamador já serializa as gravações por outro bloqueio
     *                              (fechamento: competência bloqueada, que as justificativas leem com bloqueio
     *                              compartilhado antes de gravar); evita inverter a ordem de bloqueio
     *                              funcionário → competência usada pelas justificativas
     * @return Collection<int, TimesheetSnapshot>
     */
    public function persist(Employee $employee, array $captured, TimesheetSnapshotSource $source, ?User $user, array $context = [], bool $lockEmployee = true): Collection
    {
        return DB::transaction(function () use ($employee, $captured, $source, $user, $context, $lockEmployee) {
            $locked = $lockEmployee ? Employee::query()->lockForUpdate()->find($employee->getKey()) : Employee::query()->find($employee->getKey());

            if ($locked === null) {
                throw new DomainException('Funcionário não encontrado.');
            }

            $this->ensureContext($locked, $captured, $source, $context);

            $existing = TimesheetSnapshot::query()
                ->where('employee_id', $locked->getKey())
                ->whereIn('date', array_keys($captured))
                ->orderBy('sequence')
                ->get(['id', 'date', 'sequence', 'source', 'inputs_hash', 'rules_hash', 'result_hash'])
                ->groupBy(fn (TimesheetSnapshot $snapshot) => $snapshot->date->toDateString());

            $created = collect();

            foreach ($captured as $date => $day) {
                $versions = $existing->get($date, collect());
                $latest = $versions->last();

                if ($source === TimesheetSnapshotSource::LateBaseline && $versions->contains(fn (TimesheetSnapshot $snapshot) => $snapshot->source->isBaseline())) {
                    continue;
                }

                if ($source === TimesheetSnapshotSource::Recalculation && $latest !== null
                    && [$latest->inputs_hash, $latest->rules_hash, $latest->result_hash] === [$day['inputs_hash'], $day['rules_hash'], $day['result_hash']]) {
                    continue;
                }

                $created->push(TimesheetSnapshot::query()->create([
                    'employee_id' => $locked->getKey(),
                    'date' => $date,
                    'sequence' => ($latest?->sequence ?? 0) + 1,
                    'source' => $source,
                    'competence' => CarbonImmutable::parse($date)->startOfMonth()->addMonthNoOverflow()->toDateString(),
                    'benefit_period_id' => $context['benefit_period_id'] ?? null,
                    'point_justification_history_id' => $context['point_justification_history_id'] ?? null,
                    'operation_uuid' => $context['operation_uuid'] ?? null,
                    'schema_version' => self::SCHEMA_VERSION,
                    'rule_version' => self::RULE_VERSION,
                    'rules_hash' => $day['rules_hash'],
                    'inputs' => $day['inputs'],
                    'inputs_hash' => $day['inputs_hash'],
                    'result' => $day['result'],
                    'result_hash' => $day['result_hash'],
                    'reason' => $context['reason'] ?? null,
                    'created_by' => $user?->getKey(),
                    'created_by_name' => $user?->name ?? 'Sistema',
                ]));
            }

            return $created;
        });
    }

    /**
     * Ponto de partida do espelho no fechamento da competência, dentro da transação do
     * fechamento. Grava `closure_baseline` para todos os dias da janela de cada funcionário
     * abrangido, com o mesmo `operation_uuid`. Devolve a quantidade gravada.
     */
    public function recordClosureBaseline(BenefitPeriod $period, ?User $user, string $operationUuid, string $reason): int
    {
        $start = $period->windowStart();
        $end = $period->windowEnd();
        $dates = collect(CarbonPeriod::create($start, $end))->map(fn (CarbonInterface $date) => $date->toDateString())->all();
        $context = ['benefit_period_id' => (int) $period->getKey(), 'operation_uuid' => $operationUuid, 'reason' => $reason];
        $recorded = 0;

        $this->closureEmployees($start, $end)->chunkById(100, function (Collection $employees) use ($dates, $user, $context, &$recorded) {
            foreach ($employees as $employee) {
                $recorded += $this->persist($employee, $this->capture($employee, $dates), TimesheetSnapshotSource::ClosureBaseline, $user, $context, lockEmployee: false)->count();
            }
        });

        return $recorded;
    }

    /**
     * Funcionários abrangidos pelo fechamento: sem rescisão antes do início da janela, ou
     * com batida ou justificativa na janela (dado que o espelho mostraria).
     *
     * @return Builder<Employee>
     */
    public function closureEmployees(CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return Employee::query()
            ->where(fn (Builder $query) => $query
                ->whereNull('recision_date')
                ->orWhere('recision_date', '>=', $start->toDateString())
                ->orWhereExists(fn ($points) => $points->selectRaw('1')->from('points')
                    ->whereColumn('points.pis', 'employees.pis')
                    ->whereBetween('points.date', [$start->toDateString(), $end->toDateString()]))
                ->orWhereExists(fn ($justifications) => $justifications->selectRaw('1')->from('point_justifications')
                    ->whereColumn('point_justifications.employee_id', 'employees.id')
                    ->whereNull('point_justifications.canceled_at')
                    ->whereBetween('point_justifications.occurrence_date', [$start->subDay()->toDateString(), $end->toDateString()])))
            ->orderBy('id');
    }

    /**
     * Compara o snapshot com as entradas e o código atuais. Divergência de entradas indica
     * mudança no dia depois do registro (inclusive por caminhos sem snapshot); não indica
     * quantas mudanças houve nem as preserva.
     *
     * @return array{inputs_changed: bool, rules_changed: bool, result_changed: bool, current_inputs_hash: string, changed_result_fields: list<string>}
     */
    public function divergence(TimesheetSnapshot $snapshot): array
    {
        return self::compare($snapshot, $this->capture($snapshot->employee, [$snapshot->date->toDateString()])[$snapshot->date->toDateString()]);
    }

    /**
     * Compara o snapshot gravado com um dia capturado agora (`capture()`), sem gravar nada.
     * `changed_result_fields`: campos do resultado apresentado cujo valor mudou.
     *
     * @param  array{inputs_hash: string, rules_hash: string, result_hash: string, result: array<string, mixed>}  $current
     * @return array{inputs_changed: bool, rules_changed: bool, result_changed: bool, current_inputs_hash: string, changed_result_fields: list<string>}
     */
    public static function compare(TimesheetSnapshot $snapshot, array $current): array
    {
        $stored = is_array($snapshot->result) ? $snapshot->result : [];
        $fields = collect(array_keys([...$stored, ...$current['result']]))
            ->filter(fn (string $key) => self::hash(['value' => $stored[$key] ?? null]) !== self::hash(['value' => $current['result'][$key] ?? null]))
            ->values()->all();

        return [
            'inputs_changed' => ! hash_equals((string) $snapshot->inputs_hash, $current['inputs_hash']),
            'rules_changed' => ! hash_equals((string) $snapshot->rules_hash, $current['rules_hash']),
            'result_changed' => ! hash_equals((string) $snapshot->result_hash, $current['result_hash']),
            'current_inputs_hash' => $current['inputs_hash'],
            'changed_result_fields' => $fields,
        ];
    }

    /**
     * SHA-256 do JSON canônico: chaves de objetos ordenadas, listas na ordem recebida.
     *
     * @param  array<mixed>  $data
     */
    public static function hash(array $data): string
    {
        return hash('sha256', 'v'.self::SCHEMA_VERSION.':'.json_encode(self::canonical(self::normalize($data)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    /**
     * SHA-256 do código-fonte das classes de cálculo (RULE_SOURCES), calculado uma vez por processo.
     */
    public static function rulesHash(): string
    {
        return self::$rulesHash ??= hash('sha256', collect(self::RULE_SOURCES)
            ->map(fn (string $class) => $class.':'.hash_file('sha256', (string) (new ReflectionClass($class))->getFileName()))
            ->implode("\n"));
    }

    /**
     * Enums pelo valor e datas como texto, recursivamente.
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private static function normalize(array $data): array
    {
        return array_map(fn (mixed $value) => match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof CarbonInterface => $value->toIso8601String(),
            is_array($value) => self::normalize($value),
            default => $value,
        }, $data);
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private static function canonical(array $data): array
    {
        if (! array_is_list($data)) {
            ksort($data, SORT_STRING);
        }

        return array_map(fn (mixed $value) => is_array($value) ? self::canonical($value) : $value, $data);
    }

    /**
     * @param  Collection<int, Point>  $points
     * @param  array<int, string>  $labels
     * @param  list<PointJustification>  $justifications
     * @param  array<int, int>  $lastHistories
     * @return array{calculation: array<string, mixed>, references: array<string, mixed>}
     */
    private function inputs(Employee $employee, CarbonImmutable $date, Collection $points, array $labels, array $justifications, array $lastHistories, string $dayType): array
    {
        $points = $points->sortBy([fn (Point $a, Point $b) => [substr((string) $a->getRawOriginal('time'), 0, 8), $a->type, $a->id] <=> [substr((string) $b->getRawOriginal('time'), 0, 8), $b->type, $b->id]])->values();
        $schedule = config('timesheet.schedule.'.$date->dayOfWeekIso);
        $alternativeEnabled = $this->alternativeRule->isEnabled();

        return [
            'calculation' => [
                'employee_pis' => (string) $employee->pis,
                'date' => $date->toDateString(),
                'day_of_week_iso' => $date->dayOfWeekIso,
                'day_type' => $dayType,
                'holiday' => Holiday::descriptionFor($date->toDateString()),
                'schedule' => $schedule === null ? null : collect(['entry', 'break_start', 'break_end', 'exit'])->mapWithKeys(fn (string $key) => [$key => $schedule[$key] ?? null])->all(),
                'entry_tolerance_minutes' => max(0, (int) config('timesheet.entry_tolerance_minutes', 0)),
                'points' => $points->map(fn (Point $point) => [
                    'time' => substr((string) $point->getRawOriginal('time'), 0, 8),
                    'type' => (string) $point->type,
                    'hr_annotation' => $labels[$point->id] ?? null,
                ])->all(),
                'justifications' => array_map(fn (PointJustification $justification) => [
                    'occurrence_date' => $justification->occurrence_date->toDateString(),
                    'start_time' => $justification->start_time,
                    'end_time' => $justification->end_time,
                    'ends_next_day' => (bool) $justification->ends_next_day,
                    'punch_kind' => $justification->punch_kind?->value,
                    'treatment' => $justification->treatment->value,
                    'allowed_minutes' => $justification->allowed_minutes,
                    'reason_name' => $justification->reason->name,
                    'reason_category' => $justification->reason->category->value,
                ], $justifications),
                'overtime_rule' => [
                    'monday_to_thursday_minutes' => OvertimeCalculator::MONDAY_TO_THURSDAY_MINUTES,
                    'friday_minutes' => OvertimeCalculator::FRIDAY_MINUTES,
                    'default_lunch_threshold_minutes' => OvertimeCalculator::DEFAULT_LUNCH_THRESHOLD_MINUTES,
                    'default_lunch_minutes' => OvertimeCalculator::DEFAULT_LUNCH_MINUTES,
                ],
                'alternative_rule' => $alternativeEnabled ? [
                    'enabled' => true,
                    'threshold_minutes' => AlternativeTimesheetRule::THRESHOLD_MINUTES,
                    'night_from' => AlternativeTimesheetRule::NIGHT_FROM,
                    'night_until' => AlternativeTimesheetRule::NIGHT_UNTIL,
                    'max_span_minutes' => AlternativeTimesheetRule::MAX_SPAN_MINUTES,
                    'lunch_boundary_pairs' => AlternativeTimesheetRule::LUNCH_BOUNDARY_PAIRS,
                ] : ['enabled' => false],
            ],
            'references' => [
                'employee' => ['id' => (int) $employee->getKey(), 'name' => (string) $employee->name, 'pis' => (string) $employee->pis],
                'points' => $points->map(fn (Point $point) => [
                    'id' => (int) $point->id,
                    'time' => substr((string) $point->getRawOriginal('time'), 0, 8),
                    'type' => (string) $point->type,
                    'rep_serial' => $point->rep_serial,
                    'nsr' => $point->nsr === null ? null : (int) $point->nsr,
                    'afd_import_id' => $point->afd_import_id === null ? null : (int) $point->afd_import_id,
                ])->all(),
                'justifications' => array_map(fn (PointJustification $justification) => [
                    'id' => (int) $justification->getKey(),
                    'version' => (int) $justification->version,
                    'last_history_id' => $lastHistories[(int) $justification->getKey()] ?? null,
                    'reason_id' => (int) $justification->reason_id,
                    'reason_code' => $justification->reason->code,
                ], $justifications),
            ],
        ];
    }

    /**
     * Último registro de histórico de cada justificativa.
     *
     * @param  list<int>  $justificationIds
     * @return array<int, int>
     */
    private function lastHistoryIds(array $justificationIds): array
    {
        if ($justificationIds === []) {
            return [];
        }

        return PointJustificationHistory::query()
            ->whereIn('point_justification_id', $justificationIds)
            ->groupBy('point_justification_id')
            ->selectRaw('point_justification_id, max(id) as last_id')
            ->pluck('last_id', 'point_justification_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  array<string, array<string, mixed>>  $captured
     * @param  array<string, mixed>  $context
     */
    private function ensureContext(Employee $employee, array $captured, TimesheetSnapshotSource $source, array $context): void
    {
        foreach ($captured as $date => $day) {
            if ((int) $day['employee_id'] !== (int) $employee->getKey() || $day['date'] !== $date) {
                throw new DomainException('Snapshot capturado para outro funcionário ou data.');
            }
        }

        if (in_array($source, [TimesheetSnapshotSource::BeforeChange, TimesheetSnapshotSource::AfterChange], true) && blank($context['operation_uuid'] ?? null)) {
            throw new DomainException('Snapshot antes/depois de alteração exige o identificador da operação.');
        }

        $historyId = $context['point_justification_history_id'] ?? null;

        if ($historyId !== null) {
            $belongs = PointJustificationHistory::query()
                ->whereKey($historyId)
                ->whereHas('justification', fn ($query) => $query->where('employee_id', $employee->getKey()))
                ->exists();

            if (! $belongs) {
                throw new DomainException('O histórico informado não pertence a uma justificativa deste funcionário.');
            }
        }
    }
}
