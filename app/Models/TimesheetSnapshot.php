<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\TimesheetSnapshotSource;
use App\Services\TimesheetSnapshotRecorder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Snapshot append-only de um dia do espelho de ponto (F6.2a). Gravado somente pelo
 * TimesheetSnapshotRecorder; nunca editado nem apagado pela aplicação: os eventos do
 * model recusam a edição e a exclusão de um registro, e o builder recusa UPDATE, DELETE,
 * UPSERT e incremento em massa (`TimesheetSnapshot::query()->update(...)`). Alteração
 * direta no banco (fora do Eloquent) não é impedida aqui; `record_hash` permite detectá-la
 * (`integrityProblems()`).
 *
 * `inputs`: entradas do cálculo do dia (estrutura em TimesheetSnapshotRecorder::inputs).
 * `result`: o dia como o TimesheetMirror apresentou, com enums gravados pelo valor.
 */
class TimesheetSnapshot extends Model
{
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => DateOnly::class,
            'competence' => DateOnly::class,
            'sequence' => 'integer',
            'source' => TimesheetSnapshotSource::class,
            'schema_version' => 'integer',
            'inputs' => 'array',
            'result' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * Colunas cobertas por `record_hash`. O usuário fica de fora: a chave estrangeira o
     * anula quando ele é removido; o nome copiado continua coberto.
     *
     * @var list<string>
     */
    public const RECORD_HASH_COLUMNS = [
        'employee_id', 'date', 'sequence', 'source', 'competence', 'benefit_period_id', 'point_justification_history_id',
        'operation_uuid', 'schema_version', 'rule_version', 'rules_hash', 'inputs_hash', 'result_hash', 'reason', 'created_by_name',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $snapshot) {
            $snapshot->setAttribute('record_hash', $snapshot->computedRecordHash());
        });

        static::updating(function () {
            throw new LogicException('Snapshot do espelho não pode ser editado: o registro é append-only.');
        });

        static::deleting(function () {
            throw new LogicException('Snapshot do espelho não pode ser apagado: o registro é append-only.');
        });
    }

    /**
     * Builder que recusa as escritas em massa, que não passam pelos eventos do model.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return Builder<self>
     */
    public function newEloquentBuilder($query): Builder
    {
        return new class($query) extends Builder
        {
            public function update(array $values)
            {
                throw new LogicException('Snapshots do espelho são append-only: atualização em massa recusada.');
            }

            public function delete()
            {
                throw new LogicException('Snapshots do espelho são append-only: exclusão em massa recusada.');
            }

            public function forceDelete()
            {
                $this->delete();
            }

            public function upsert(array $values, $uniqueBy, $update = null)
            {
                throw new LogicException('Snapshots do espelho são append-only: upsert recusado.');
            }

            public function increment($column, $amount = 1, array $extra = [])
            {
                throw new LogicException('Snapshots do espelho são append-only: incremento recusado.');
            }

            public function decrement($column, $amount = 1, array $extra = [])
            {
                throw new LogicException('Snapshots do espelho são append-only: decremento recusado.');
            }
        };
    }

    /**
     * SHA-256 canônico das colunas gravadas (RECORD_HASH_COLUMNS), na forma do banco.
     */
    public function computedRecordHash(): string
    {
        $values = [];

        foreach (self::RECORD_HASH_COLUMNS as $column) {
            $value = $this->getAttributeFromArray($column);
            $values[$column] = $value === null ? null : (string) $value;
        }

        return TimesheetSnapshotRecorder::hash($values);
    }

    /**
     * Divergências entre o registro e os seus hashes (alteração feita fora da aplicação).
     *
     * @return list<string>
     */
    public function integrityProblems(): array
    {
        return array_values(array_filter([
            hash_equals((string) $this->record_hash, $this->computedRecordHash()) ? null : 'record_hash',
            hash_equals((string) $this->inputs_hash, TimesheetSnapshotRecorder::hash($this->inputs['calculation'] ?? [])) ? null : 'inputs_hash',
            hash_equals((string) $this->result_hash, TimesheetSnapshotRecorder::hash($this->result ?? [])) ? null : 'result_hash',
        ]));
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<BenefitPeriod, $this>
     */
    public function benefitPeriod(): BelongsTo
    {
        return $this->belongsTo(BenefitPeriod::class);
    }

    /**
     * @return BelongsTo<PointJustificationHistory, $this>
     */
    public function justificationHistory(): BelongsTo
    {
        return $this->belongsTo(PointJustificationHistory::class, 'point_justification_history_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
