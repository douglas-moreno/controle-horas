<?php

namespace App\Services;

use App\Enums\JustificationHistoryAction;
use App\Enums\JustificationTreatment;
use App\Enums\PunchKind;
use App\Models\Point;
use App\Models\PointJustification;
use App\Models\PointJustificationHistory;
use App\Models\PointJustificationReason;
use Carbon\CarbonImmutable;

/**
 * Apresentação somente leitura do histórico de uma justificativa (F6.1). Nunca grava.
 *
 * - Linha do tempo na ordem de gravação, com ação, data/hora, usuário (nome copiado no
 *   momento da operação), competência e indicação de competência fechada.
 * - Campos: somente os conhecidos (FIELD_LABELS), formatados como texto; nunca o JSON
 *   bruto. A edição mostra apenas os campos alterados, com o valor anterior e o novo.
 * - Motivo: o nome do retrato gravado no histórico até aquele momento (registro e
 *   edição gravam o motivo). Sem retrato, cai no nome atual do cadastro, indicado como tal.
 * - Marcação vinculada: a atual, quando ainda existe; senão, o último retrato do
 *   histórico (removida no cancelamento ou ausente por remoção fora do fluxo).
 */
class PointJustificationHistoryPresenter
{
    /**
     * @var array<string, string>
     */
    public const FIELD_LABELS = [
        'reason_id' => 'Motivo',
        'occurrence_date' => 'Data',
        'start_time' => 'Horário inicial',
        'end_time' => 'Horário final',
        'ends_next_day' => 'Término no dia seguinte',
        'punch_kind' => 'Tipo de marcação',
        'treatment' => 'Tratamento',
        'allowed_minutes' => 'Minutos abonados',
        'observation' => 'Observação',
    ];

    public function __construct(private ManualPointRegistrar $registrar) {}

    /**
     * @return array{
     *     justification: array{id: int, reason: string, occurrence_date: string, canceled: bool},
     *     entries: list<array{id: int, action: string, performed_at: string, performed_by: string, competence: ?string, closed_period: bool, description: ?string, changes: list<array{field: string, before: ?string, after: ?string}>, point_before: ?string, point_after: ?string, cancellation_reason: ?string}>,
     *     linked_point: ?array{status: string, point_id: ?int, date: string, time: string, type: ?string, protection: ?string, removed_at: ?string, removed_by: ?string}
     * }
     */
    public function present(PointJustification $justification): array
    {
        $histories = $justification->histories()->get();
        $reasonNames = $this->reasonNames($justification, $histories->all());

        $entries = [];
        $knownReasons = [];

        foreach ($histories as $history) {
            $reasonSnapshot = $history->new_values['reason'] ?? null;

            if (is_array($reasonSnapshot) && isset($reasonSnapshot['id'], $reasonSnapshot['name'])) {
                $knownReasons[(int) $reasonSnapshot['id']] = (string) $reasonSnapshot['name'];
            }

            $entries[] = $this->entry($history, fn (mixed $reasonId) => $this->reasonName($reasonId, $knownReasons, $reasonNames));
        }

        return [
            'justification' => [
                'id' => (int) $justification->getKey(),
                'reason' => $this->reasonName($justification->reason_id, $knownReasons, $reasonNames),
                'occurrence_date' => $justification->occurrence_date->format('d/m/Y'),
                'canceled' => $justification->isCanceled(),
            ],
            'entries' => $entries,
            'linked_point' => $this->linkedPoint($justification, $histories->all()),
        ];
    }

    /**
     * @param  callable(mixed): string  $reasonName
     * @return array{id: int, action: string, performed_at: string, performed_by: string, competence: ?string, closed_period: bool, description: ?string, changes: list<array{field: string, before: ?string, after: ?string}>, point_before: ?string, point_after: ?string, cancellation_reason: ?string}
     */
    private function entry(PointJustificationHistory $history, callable $reasonName): array
    {
        $before = $history->old_values['justification'] ?? null;
        $after = $history->new_values['justification'] ?? null;

        $changes = match ($history->action) {
            JustificationHistoryAction::Created => $this->changes(null, is_array($after) ? $after : [], $reasonName),
            JustificationHistoryAction::Updated => $this->changes(is_array($before) ? $before : [], is_array($after) ? $after : [], $reasonName),
            default => [],
        };

        $pointBefore = $history->old_values['manual_point'] ?? null;
        $pointAfter = $history->new_values['manual_point'] ?? null;
        $showsPoint = in_array($history->action, [JustificationHistoryAction::ManualPointCreated, JustificationHistoryAction::ManualPointUpdated, JustificationHistoryAction::ManualPointRemoved], true);

        return [
            'id' => (int) $history->getKey(),
            'action' => $history->action->label(),
            'performed_at' => $history->created_at->format('d/m/Y H:i:s'),
            'performed_by' => (string) $history->performed_by_name,
            'competence' => $history->affected_competence?->format('m/Y'),
            'closed_period' => (bool) $history->closed_period,
            'description' => $history->description,
            'changes' => $changes,
            'point_before' => $showsPoint && is_array($pointBefore) ? $this->formatPoint($pointBefore) : null,
            'point_after' => $showsPoint && is_array($pointAfter) ? $this->formatPoint($pointAfter) : null,
            'cancellation_reason' => $history->action === JustificationHistoryAction::Canceled ? ($history->new_values['cancellation_reason'] ?? null) : null,
        ];
    }

    /**
     * Campos conhecidos com valor anterior e novo. Sem `$before` (registro), todos os
     * campos preenchidos; na edição, somente os alterados.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after
     * @param  callable(mixed): string  $reasonName
     * @return list<array{field: string, before: ?string, after: ?string}>
     */
    private function changes(?array $before, array $after, callable $reasonName): array
    {
        $changes = [];

        foreach (self::FIELD_LABELS as $field => $label) {
            $old = $before === null ? null : $this->formatValue($field, $before[$field] ?? null, $reasonName);
            $new = $this->formatValue($field, $after[$field] ?? null, $reasonName);

            $isEmpty = $new === null || ($field === 'ends_next_day' && $new === 'Não');

            if ($before === null ? $isEmpty : $old === $new) {
                continue;
            }

            $changes[] = ['field' => $label, 'before' => $old, 'after' => $new];
        }

        return $changes;
    }

    /**
     * @param  callable(mixed): string  $reasonName
     */
    private function formatValue(string $field, mixed $value, callable $reasonName): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($field) {
            'reason_id' => $reasonName($value),
            'occurrence_date' => CarbonImmutable::parse((string) $value)->format('d/m/Y'),
            'start_time', 'end_time' => substr((string) $value, 0, 5),
            'ends_next_day' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'Sim' : 'Não',
            'punch_kind' => PunchKind::tryFrom((string) $value)?->label() ?? (string) $value,
            'treatment' => JustificationTreatment::tryFrom((string) $value)?->label() ?? (string) $value,
            'allowed_minutes' => OvertimeCalculator::format((int) $value),
            default => (string) $value,
        };
    }

    /**
     * Nome do motivo gravado no histórico até o momento; sem retrato, o nome atual do
     * cadastro, indicado como tal.
     *
     * @param  array<int, string>  $knownReasons
     * @param  array<int, string>  $currentNames
     */
    private function reasonName(mixed $reasonId, array $knownReasons, array $currentNames): string
    {
        $id = (int) $reasonId;

        return $knownReasons[$id] ?? (isset($currentNames[$id]) ? $currentNames[$id].' (nome atual do cadastro)' : 'Motivo #'.$id);
    }

    /**
     * Nomes atuais dos motivos citados, usados só quando o histórico não tem retrato.
     *
     * @param  list<PointJustificationHistory>  $histories
     * @return array<int, string>
     */
    private function reasonNames(PointJustification $justification, array $histories): array
    {
        $ids = [(int) $justification->reason_id];

        foreach ($histories as $history) {
            foreach ([$history->old_values['justification']['reason_id'] ?? null, $history->new_values['justification']['reason_id'] ?? null] as $id) {
                if ($id !== null) {
                    $ids[] = (int) $id;
                }
            }
        }

        return PointJustificationReason::query()->whereKey(array_unique($ids))->pluck('name', 'id')->map(fn ($name) => (string) $name)->all();
    }

    /**
     * Marcação vinculada: a atual, quando existe; senão, o último retrato do histórico.
     *
     * @param  list<PointJustificationHistory>  $histories
     * @return array{status: string, point_id: ?int, date: string, time: string, type: ?string, protection: ?string, removed_at: ?string, removed_by: ?string}|null
     */
    private function linkedPoint(PointJustification $justification, array $histories): ?array
    {
        $point = $justification->manual_point_id === null ? null : Point::query()->find($justification->manual_point_id);

        if ($point !== null) {
            return [
                'status' => 'current',
                'point_id' => (int) $point->getKey(),
                'date' => $point->date->format('d/m/Y'),
                'time' => substr((string) $point->getRawOriginal('time'), 0, 5),
                'type' => (string) $point->type,
                'protection' => $this->registrar->protectionReason($point),
                'removed_at' => null,
                'removed_by' => null,
            ];
        }

        $snapshot = null;
        $removal = null;

        foreach ($histories as $history) {
            foreach ([$history->old_values['manual_point'] ?? null, $history->new_values['manual_point'] ?? null] as $candidate) {
                if (is_array($candidate) && isset($candidate['date'], $candidate['time'])) {
                    $snapshot = $candidate;
                }
            }

            if ($history->action === JustificationHistoryAction::ManualPointRemoved) {
                $removal = $history;
            }
        }

        if ($snapshot === null) {
            return null;
        }

        return [
            'status' => $removal !== null ? 'removed' : 'missing',
            'point_id' => isset($snapshot['id']) ? (int) $snapshot['id'] : null,
            'date' => CarbonImmutable::parse((string) $snapshot['date'])->format('d/m/Y'),
            'time' => substr((string) $snapshot['time'], 0, 5),
            'type' => isset($snapshot['type']) ? (string) $snapshot['type'] : null,
            'protection' => null,
            'removed_at' => $removal?->created_at->format('d/m/Y H:i:s'),
            'removed_by' => $removal === null ? null : (string) $removal->performed_by_name,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function formatPoint(array $snapshot): string
    {
        if (! isset($snapshot['date'], $snapshot['time'])) {
            return '—';
        }

        return CarbonImmutable::parse((string) $snapshot['date'])->format('d/m/Y').' '.substr((string) $snapshot['time'], 0, 5);
    }
}
