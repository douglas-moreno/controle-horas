<?php

namespace App\Services;

use App\Enums\BenefitPeriodStatus;
use App\Enums\TimesheetSnapshotSource;
use App\Models\BenefitPeriod;
use App\Models\Employee;
use App\Models\TimesheetSnapshot;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Consulta (somente leitura) dos snapshots do espelho de ponto (F6.2d): versões de um
 * funcionário e dia, snapshot de referência, aviso de divergência e grupo de impressão.
 * Nada aqui grava: o estado atual é lido por `TimesheetSnapshotRecorder::capture()`, que
 * não persiste.
 *
 * Snapshot de referência de um dia (o estado aceito mais recente da competência fechada):
 * 1. A competência do dia precisa estar fechada agora. Aberta ou calculada (inclusive após
 *    reabertura), mudanças são esperadas e não há referência (`open`).
 * 2. Só contam snapshots ligados a essa competência. O ponto de partida é o baseline
 *    (`closure_baseline` ou `late_baseline`) de maior versão; sem baseline, `missing`.
 * 3. A referência é o último snapshot a partir desse baseline que não seja `before_change`
 *    (o "antes" retrata o estado substituído pela alteração autorizada seguinte).
 * 4. Referência que não passa na verificação de integridade vira `integrity`: não é usada
 *    para afirmar divergência, nem substituída por outra mais antiga.
 * 5. `stale`: o baseline é de antes do último fechamento da competência (o funcionário não
 *    entrou nele). Mudanças feitas com a competência aberta também aparecem como divergência.
 *
 * Por que não "o último snapshot": o último pode ser `before_change` (nunca é, numa operação
 * concluída, mas a regra não depende disso), pode ser de outra competência ou de um ciclo
 * anterior à reabertura.
 */
class TimesheetSnapshotHistory
{
    /**
     * Rótulos dos campos do resultado apresentado, para o aviso de divergência.
     *
     * @var array<string, string>
     */
    public const RESULT_FIELD_LABELS = [
        'entrada' => 'Entrada',
        'almoco_inicio' => 'Saída para intervalo',
        'almoco_fim' => 'Retorno do intervalo',
        'saida' => 'Saída',
        'punch_count' => 'Quantidade de marcações',
        'has_manual_punch' => 'Marcação manual',
        'hr_annotations' => 'Anotações do RH',
        'day_type' => 'Tipo do dia',
        'holiday' => 'Feriado',
        'status' => 'Ocorrência',
        'status_label' => 'Ocorrência',
        'needs_review' => 'Conferência',
        'justified' => 'Justificado',
        'overtime_minutes' => 'Hora extra',
        'overtime' => 'Hora extra',
        'late_entry_minutes' => 'Atraso',
        'early_leave_minutes' => 'Atraso',
        'delay_before_allowance_minutes' => 'Atraso',
        'allowed_delay_minutes' => 'Abono',
        'delay_minutes' => 'Atraso',
        'delay_before_allowance' => 'Atraso',
        'allowed_delay' => 'Abono',
        'delay' => 'Atraso',
        'justifications' => 'Justificativas',
        'justification_notes' => 'Justificativas',
        'alternative' => 'Regra alternativa',
    ];

    /**
     * Último fechamento por competência, memorizado na instância (impressão de vários funcionários).
     *
     * @var array<int, array{operation_uuid: ?string, first_id: int}|null>
     */
    private array $latestClosures = [];

    public function __construct(private TimesheetSnapshotRecorder $recorder) {}

    /**
     * Todas as versões gravadas do funcionário no dia, em ordem de versão.
     *
     * @return Collection<int, TimesheetSnapshot>
     */
    public function versions(Employee $employee, string $date): Collection
    {
        return TimesheetSnapshot::query()
            ->with('benefitPeriod')
            ->where('employee_id', $employee->getKey())
            ->where('date', CarbonImmutable::parse($date)->toDateString())
            ->orderBy('sequence')
            ->get();
    }

    /**
     * @return array{state: 'open'|'missing'|'integrity'|'reference', snapshot: ?TimesheetSnapshot, period: ?BenefitPeriod, stale: bool}
     */
    public function reference(Employee $employee, string $date): array
    {
        $date = CarbonImmutable::parse($date)->toDateString();

        return $this->references($employee, [$date])[$date];
    }

    /**
     * Referência de cada data (critério na documentação da classe), com uma consulta de
     * snapshots para todas as datas.
     *
     * @param  list<string>  $dates
     * @return array<string, array{state: 'open'|'missing'|'integrity'|'reference', snapshot: ?TimesheetSnapshot, period: ?BenefitPeriod, stale: bool}>
     */
    public function references(Employee $employee, array $dates): array
    {
        $dates = collect($dates)->map(fn (string $date) => CarbonImmutable::parse($date)->toDateString())->unique()->values();
        $competences = $dates->mapWithKeys(fn (string $date) => [$date => self::competenceOf($date)]);

        $periods = BenefitPeriod::query()
            ->whereIn('competence', $competences->unique()->values()->all())
            ->get()
            ->keyBy(fn (BenefitPeriod $period) => $period->competence->toDateString());
        $closed = $periods->filter(fn (BenefitPeriod $period) => $period->status === BenefitPeriodStatus::Closed);

        $versions = $closed->isEmpty() ? collect() : TimesheetSnapshot::query()
            ->where('employee_id', $employee->getKey())
            ->whereIn('date', $dates->all())
            ->whereIn('benefit_period_id', $closed->modelKeys())
            ->orderBy('sequence')
            ->get()
            ->groupBy(fn (TimesheetSnapshot $snapshot) => $snapshot->date->toDateString());

        $latestClosures = $closed->mapWithKeys(fn (BenefitPeriod $period) => [$period->getKey() => $this->latestClosure($period)]);

        return $dates->mapWithKeys(function (string $date) use ($competences, $periods, $versions, $latestClosures) {
            $period = $periods->get($competences[$date]);

            if ($period === null || $period->status !== BenefitPeriodStatus::Closed) {
                return [$date => ['state' => 'open', 'snapshot' => null, 'period' => $period, 'stale' => false]];
            }

            $days = $versions->get($date, collect())->where('benefit_period_id', $period->getKey())->values();
            $baseline = $days->last(fn (TimesheetSnapshot $snapshot) => $snapshot->source->isBaseline());

            if ($baseline === null) {
                return [$date => ['state' => 'missing', 'snapshot' => null, 'period' => $period, 'stale' => false]];
            }

            $reference = $days->last(fn (TimesheetSnapshot $snapshot) => $snapshot->sequence >= $baseline->sequence
                && $snapshot->source !== TimesheetSnapshotSource::BeforeChange);
            $closure = $latestClosures[$period->getKey()];
            $stale = $closure !== null && $baseline->operation_uuid !== $closure['operation_uuid'] && $baseline->id < $closure['first_id'];

            return [$date => [
                'state' => $reference->integrityProblems() === [] ? 'reference' : 'integrity',
                'snapshot' => $reference,
                'period' => $period,
                'stale' => $stale,
            ]];
        })->all();
    }

    /**
     * Compara a referência de cada data com o estado atual (uma captura para todas as
     * datas com referência válida). Sem gravação.
     *
     * @param  array<string, array{state: string, snapshot: ?TimesheetSnapshot, period: ?BenefitPeriod, stale: bool}>  $references
     * @return array<string, array{inputs_changed: bool, rules_changed: bool, result_changed: bool, current_inputs_hash: string, changed_result_fields: list<string>}>
     */
    public function comparisons(Employee $employee, array $references): array
    {
        $valid = array_filter($references, fn (array $reference) => $reference['state'] === 'reference');

        if ($valid === []) {
            return [];
        }

        $current = $this->recorder->capture($employee, array_keys($valid));

        return collect($valid)->map(fn (array $reference, string $date) => TimesheetSnapshotRecorder::compare($reference['snapshot'], $current[$date]))->all();
    }

    /**
     * Aviso do dia para a tela: divergência do resultado, mudança só nas entradas ou falha
     * de integridade da referência. Null quando não há o que avisar (inclusive competência
     * aberta, dia sem ponto de partida ou mudança apenas no código das regras).
     * Sem `$withAuthor`, o nome do responsável pelo registro não entra na mensagem nem na
     * identificação da referência (usuário sem a permissão do RH).
     *
     * @return array{level: 'warning'|'info'|'danger', title: string, message: string, fields: list<string>, stale: bool, reference: array<string, mixed>}|null
     */
    public function notice(Employee $employee, string $date, bool $withAuthor = true): ?array
    {
        $date = CarbonImmutable::parse($date)->toDateString();
        $references = $this->references($employee, [$date]);

        return $this->noticeFor($references[$date], $this->comparisons($employee, $references)[$date] ?? null, $withAuthor);
    }

    /**
     * Avisos dos dias do período para a impressão do espelho atual: só divergência do
     * resultado apresentado e falha de integridade da referência.
     *
     * @return list<array{date: string, level: 'warning'|'danger', message: string}>
     */
    public function printNotices(Employee $employee, CarbonInterface $start, CarbonInterface $end): array
    {
        $dates = [];

        for ($day = CarbonImmutable::parse($start->toDateString()); $day->lte($end); $day = $day->addDay()) {
            $dates[] = $day->toDateString();
        }

        $references = $this->references($employee, $dates);
        $comparisons = $this->comparisons($employee, $references);
        $notices = [];

        foreach ($references as $date => $reference) {
            $notice = $this->noticeFor($reference, $comparisons[$date] ?? null, withAuthor: false);

            if ($notice === null || $notice['level'] === 'info') {
                continue;
            }

            $snapshot = $reference['snapshot'];
            $notices[] = [
                'date' => CarbonImmutable::parse($date)->format('d/m/Y'),
                'level' => $notice['level'],
                'message' => $notice['level'] === 'danger'
                    ? 'registro de referência com falha de integridade; não é possível afirmar se confere'
                    : 'difere de '.mb_strtolower($snapshot->source->label()).' (versão '.$snapshot->sequence.', '.$snapshot->created_at->format('d/m/Y H:i').')'
                        .($notice['fields'] === [] ? '' : ': '.implode(', ', $notice['fields'])),
            ];
        }

        return $notices;
    }

    /**
     * Snapshots impressos juntos com o selecionado: os da mesma operação, mesma origem,
     * mesmo funcionário e mesma competência (no fechamento, todos os dias da janela). Uma
     * mudança de data entre competências gera uma impressão por competência (F6.3): a folha
     * traz uma única competência no cabeçalho. Sem identificador de operação, só o próprio
     * snapshot.
     *
     * @return Collection<int, TimesheetSnapshot>
     */
    public function printGroup(TimesheetSnapshot $snapshot): Collection
    {
        if ($snapshot->operation_uuid === null) {
            return collect([$snapshot]);
        }

        return TimesheetSnapshot::query()
            ->where('employee_id', $snapshot->employee_id)
            ->where('operation_uuid', $snapshot->operation_uuid)
            ->where('source', $snapshot->source)
            ->where('competence', $snapshot->competence->toDateString())
            ->orderBy('date')
            ->get();
    }

    /**
     * Versões posteriores de cada snapshot do grupo (mesmo funcionário e dia, versão maior).
     *
     * @param  Collection<int, TimesheetSnapshot>  $snapshots
     * @return array<int, list<array{sequence: int, source_label: string, created_at: string}>>
     */
    public function laterVersions(Collection $snapshots): array
    {
        if ($snapshots->isEmpty()) {
            return [];
        }

        $later = TimesheetSnapshot::query()
            ->where('employee_id', $snapshots->first()->employee_id)
            ->whereIn('date', $snapshots->map(fn (TimesheetSnapshot $snapshot) => $snapshot->date->toDateString())->all())
            ->orderBy('sequence')
            ->get(['id', 'employee_id', 'date', 'sequence', 'source', 'created_at'])
            ->groupBy(fn (TimesheetSnapshot $snapshot) => $snapshot->date->toDateString());

        return $snapshots->mapWithKeys(fn (TimesheetSnapshot $snapshot) => [$snapshot->getKey() => $later->get($snapshot->date->toDateString(), collect())
            ->filter(fn (TimesheetSnapshot $version) => $version->sequence > $snapshot->sequence)
            ->map(fn (TimesheetSnapshot $version) => [
                'sequence' => $version->sequence,
                'source_label' => $version->source->label(),
                'created_at' => $version->created_at->format('d/m/Y H:i'),
            ])->values()->all()])->all();
    }

    /**
     * Dados de identificação de um snapshot para a tela e a impressão.
     *
     * @return array<string, mixed>
     */
    public static function describe(TimesheetSnapshot $snapshot): array
    {
        return [
            'id' => (int) $snapshot->getKey(),
            'date' => $snapshot->date->format('d/m/Y'),
            'sequence' => $snapshot->sequence,
            'source' => $snapshot->source->value,
            'source_label' => $snapshot->source->label(),
            'origin_note' => self::originNote($snapshot->source),
            'competence' => $snapshot->competence->format('m/Y'),
            'created_at' => $snapshot->created_at->format('d/m/Y H:i:s'),
            'created_by_name' => $snapshot->created_by_name,
            'reason' => $snapshot->reason,
            'operation_uuid' => $snapshot->operation_uuid,
            'point_justification_history_id' => $snapshot->point_justification_history_id,
            'rule_version' => $snapshot->rule_version,
            'schema_version' => $snapshot->schema_version,
            'integrity_problems' => $snapshot->integrityProblems(),
        ];
    }

    /**
     * Explicação da origem; o ponto de partida tardio nunca é apresentado como fechamento.
     */
    public static function originNote(TimesheetSnapshotSource $source): string
    {
        return match ($source) {
            TimesheetSnapshotSource::ClosureBaseline => 'Gravado na transação do fechamento da competência.',
            TimesheetSnapshotSource::LateBaseline => 'Gravado depois do fechamento, na primeira alteração que precisou de um ponto de partida para a competência já fechada antes da implantação. Retrata o dia naquele momento; não é o resultado do fechamento original.',
            TimesheetSnapshotSource::BeforeChange => 'Estado do dia imediatamente antes de uma alteração autorizada em competência fechada.',
            TimesheetSnapshotSource::AfterChange => 'Estado do dia imediatamente depois de uma alteração autorizada em competência fechada.',
            TimesheetSnapshotSource::Recalculation => 'Novo registro do dia por recálculo autorizado.',
        };
    }

    /**
     * Rótulos (sem repetição) dos campos alterados, na ordem de `RESULT_FIELD_LABELS`.
     * A ordem não depende das chaves do JSON gravado: o MySQL reordena as chaves de uma
     * coluna JSON e o MariaDB preserva a ordem de gravação.
     *
     * @param  list<string>  $fields
     * @return list<string>
     */
    public static function fieldLabels(array $fields): array
    {
        $positions = array_flip(array_keys(self::RESULT_FIELD_LABELS));

        return collect($fields)
            ->sortBy(fn (string $field) => $positions[$field] ?? PHP_INT_MAX)
            ->map(fn (string $field) => self::RESULT_FIELD_LABELS[$field] ?? 'Outros dados do dia')
            ->unique()->values()->all();
    }

    /**
     * @param  array{state: string, snapshot: ?TimesheetSnapshot, period: ?BenefitPeriod, stale: bool}  $reference
     * @param  array{inputs_changed: bool, rules_changed: bool, result_changed: bool, changed_result_fields: list<string>}|null  $comparison
     * @return array{level: 'warning'|'info'|'danger', title: string, message: string, fields: list<string>, stale: bool, reference: array<string, mixed>}|null
     */
    private function noticeFor(array $reference, ?array $comparison, bool $withAuthor = true): ?array
    {
        $snapshot = $reference['snapshot'];

        if ($snapshot === null) {
            return null;
        }

        $described = self::describe($snapshot);
        $identification = $snapshot->source->label().', versão '.$snapshot->sequence.', registrado em '.$described['created_at'].($withAuthor ? ' por '.$snapshot->created_by_name : '');

        if (! $withAuthor) {
            unset($described['created_by_name']);
        }
        $staleNote = $reference['stale']
            ? ' A referência é anterior ao último fechamento da competência (o funcionário não entrou nele); mudanças feitas com a competência aberta também aparecem aqui.'
            : '';

        if ($reference['state'] === 'integrity') {
            return [
                'level' => 'danger',
                'title' => 'Registro histórico com falha de integridade',
                'message' => 'O registro de referência ('.$identification.') não confere com os próprios hashes ('.implode(', ', $described['integrity_problems']).'): pode ter sido alterado fora da aplicação. Não é possível afirmar se o espelho atual confere com ele.',
                'fields' => [],
                'stale' => $reference['stale'],
                'reference' => $described,
            ];
        }

        if ($comparison === null || (! $comparison['result_changed'] && ! $comparison['inputs_changed'])) {
            return null;
        }

        $fields = self::fieldLabels($comparison['changed_result_fields']);

        if (! $comparison['result_changed']) {
            return [
                'level' => 'info',
                'title' => 'Entradas do cálculo alteradas',
                'message' => 'As entradas do cálculo deste dia mudaram desde o registro de referência ('.$identification.'), mas o resultado apresentado é o mesmo.'.$staleNote,
                'fields' => [],
                'stale' => $reference['stale'],
                'reference' => $described,
            ];
        }

        return [
            'level' => 'warning',
            'title' => 'Espelho atual diverge do registro histórico',
            'message' => 'O espelho atual deste dia difere do registro de referência da competência fechada '.$snapshot->competence->format('m/Y').' ('.$identification.'). '
                .'O registro histórico não foi alterado. Mudanças por caminhos sem snapshot (importação AFD, marcações, feriados, configuração) não têm versões intermediárias preservadas.'.$staleNote,
            'fields' => $fields,
            'stale' => $reference['stale'],
            'reference' => $described,
        ];
    }

    /**
     * Último fechamento da competência: identificador da operação e primeiro id gravado nela.
     *
     * @return array{operation_uuid: ?string, first_id: int}|null
     */
    private function latestClosure(BenefitPeriod $period): ?array
    {
        if (array_key_exists($period->getKey(), $this->latestClosures)) {
            return $this->latestClosures[$period->getKey()];
        }

        $last = TimesheetSnapshot::query()
            ->where('benefit_period_id', $period->getKey())
            ->where('source', TimesheetSnapshotSource::ClosureBaseline)
            ->orderByDesc('id')
            ->first(['id', 'operation_uuid']);

        return $this->latestClosures[$period->getKey()] = $last === null ? null : [
            'operation_uuid' => $last->operation_uuid,
            'first_id' => (int) TimesheetSnapshot::query()->where('benefit_period_id', $period->getKey())->where('operation_uuid', $last->operation_uuid)->min('id'),
        ];
    }

    private static function competenceOf(string $date): string
    {
        return CarbonImmutable::parse($date)->startOfMonth()->addMonthNoOverflow()->toDateString();
    }
}
