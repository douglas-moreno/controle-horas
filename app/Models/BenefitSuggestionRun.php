<?php

namespace App\Models;

use App\Casts\DateOnly;
use Database\Factories\BenefitSuggestionRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Execução da geração de sugestões do ponto: horizonte considerado, intervalo da
 * janela efetivamente processado e contadores do resultado.
 *
 * Os campos `tail_*` e `compensation_*` (F12/S3) registram a compensação da cauda da
 * competência anterior fechada: a origem, a execução da origem que define a cobertura
 * registrada (`tail_origin_covered_until` = seu `processed_to`) e o intervalo da cauda.
 * `created` é o total criado pela execução, compensações incluídas.
 */
class BenefitSuggestionRun extends Model
{
    /** @use HasFactory<BenefitSuggestionRunFactory> */
    use HasFactory;

    /**
     * Registro somente de inserção: não há coluna updated_at.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'last_imported_on',
        'horizon_on',
        'processed_from',
        'processed_to',
        'created',
        'skipped_existing',
        'skipped_covered',
        'skipped_unobserved_days',
        'tail_origin_covered_until',
        'tail_from',
        'tail_to',
        'tail_pending_days',
        'compensation_created',
        'compensation_skipped_existing',
        'compensation_skipped_covered',
        'compensation_skipped_unobserved_days',
        'compensation_not_compensable',
    ];

    /**
     * A trilha é somente de inserção: registros existentes não são alterados nem
     * excluídos individualmente. A remoção só ocorre em cascata com a competência.
     */
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('O registro de execução das sugestões não pode ser alterado.'));
        static::deleting(fn () => throw new LogicException('O registro de execução das sugestões não pode ser excluído.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_imported_on' => DateOnly::class,
            'horizon_on' => DateOnly::class,
            'processed_from' => DateOnly::class,
            'processed_to' => DateOnly::class,
            'created' => 'integer',
            'skipped_existing' => 'integer',
            'skipped_covered' => 'integer',
            'skipped_unobserved_days' => 'integer',
            'tail_origin_covered_until' => DateOnly::class,
            'tail_from' => DateOnly::class,
            'tail_to' => DateOnly::class,
            'tail_pending_days' => 'integer',
            'compensation_created' => 'integer',
            'compensation_skipped_existing' => 'integer',
            'compensation_skipped_covered' => 'integer',
            'compensation_skipped_unobserved_days' => 'integer',
            'compensation_not_compensable' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<BenefitPeriod, $this>
     */
    public function benefitPeriod(): BelongsTo
    {
        return $this->belongsTo(BenefitPeriod::class);
    }

    /**
     * @return BelongsTo<BenefitPeriod, $this>
     */
    public function tailOriginPeriod(): BelongsTo
    {
        return $this->belongsTo(BenefitPeriod::class, 'tail_origin_period_id');
    }

    /**
     * @return BelongsTo<BenefitSuggestionRun, $this>
     */
    public function tailOriginRun(): BelongsTo
    {
        return $this->belongsTo(BenefitSuggestionRun::class, 'tail_origin_run_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
