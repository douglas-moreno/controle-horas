<?php

namespace App\Models;

use Database\Factories\PointFactory;
use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Batida do funcionário (`importado` do relógio ou `manual`).
 *
 * Batida manual mantida por decisão do RH sobre uma pendência histórica do AFD
 * (`afd_historical_nsrs` em `manual_retained` apontando para ela) não pode ser excluída nem
 * ter funcionário, data, horário ou tipo alterados: a cobertura de NSR exige que ela exista
 * e, sem ela, as importações seguintes seriam bloqueadas. A decisão precisa ser revogada
 * antes. O mesmo vale (também para REP e NSR) para o ponto referenciado por uma anotação
 * ativa do RH sobre ponto importado (AfdPointDecision: desconsiderado, duplicado ou original
 * de uma duplicada). Batida manual vinculada a uma justificativa ativa também não pode ser
 * excluída: ela só sai pelo cancelamento da justificativa (PointJustificationService), que
 * cancela antes de remover. A proteção vale para o Eloquent; exclusões diretas pelo query
 * builder ou SQL não passam por ela.
 */
class Point extends Model
{
    /** @use HasFactory<PointFactory> */
    use HasFactory;

    public const RETAINED_BY_HR_MESSAGE = 'Este ponto manual foi mantido por decisão do RH sobre uma pendência histórica do AFD e não pode ser excluído nem alterado. A decisão precisa ser revogada antes.';

    public const ANNOTATED_BY_HR_MESSAGE = 'Este ponto tem anotação do RH (desconsiderado ou batida duplicada) e não pode ser excluído nem alterado. A anotação precisa ser revogada antes.';

    public const LINKED_TO_JUSTIFICATION_MESSAGE = 'Esta marcação foi criada por uma justificativa ativa e não pode ser excluída por aqui. Edite ou cancele a justificativa na seção Justificativas.';

    protected $fillable = [
        'pis',
        'date',
        'time',
        'type',
    ];

    protected $casts = [
        'date' => 'date',
        'time' => 'datetime:H:i',
    ];

    protected static function booted(): void
    {
        static::deleting(function (Point $point) {
            if ($point->isRetainedByHrDecision()) {
                throw new DomainException(self::RETAINED_BY_HR_MESSAGE);
            }

            if ($point->isAnnotatedByHr()) {
                throw new DomainException(self::ANNOTATED_BY_HR_MESSAGE);
            }

            if ($point->isLinkedToActiveJustification()) {
                throw new DomainException(self::LINKED_TO_JUSTIFICATION_MESSAGE);
            }
        });

        static::updating(function (Point $point) {
            if ($point->isDirty(['pis', 'date', 'time', 'type']) && $point->isRetainedByHrDecision()) {
                throw new DomainException(self::RETAINED_BY_HR_MESSAGE);
            }

            if ($point->isDirty(['pis', 'date', 'time', 'type', 'rep_serial', 'nsr']) && $point->isAnnotatedByHr()) {
                throw new DomainException(self::ANNOTATED_BY_HR_MESSAGE);
            }
        });
    }

    /**
     * Batida manual gravada mantida por decisão do RH (pendência em `manual_retained`).
     */
    public function isRetainedByHrDecision(): bool
    {
        return $this->exists
            && $this->getOriginal('type', $this->type) === 'manual'
            && AfdHistoricalNsr::query()->where('related_manual_point_id', $this->getKey())->where('status', AfdHistoricalNsr::STATUS_MANUAL_RETAINED)->exists();
    }

    /**
     * Ponto gravado referenciado por uma anotação ativa do RH (AfdPointDecision).
     */
    public function isAnnotatedByHr(): bool
    {
        return $this->exists && AfdPointDecision::references((int) $this->getKey());
    }

    /**
     * Batida gravada vinculada a uma justificativa ainda não cancelada.
     */
    public function isLinkedToActiveJustification(): bool
    {
        return $this->exists && PointJustification::query()->active()->where('manual_point_id', $this->getKey())->exists();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'pis', 'pis');
    }

    /**
     * Justificativa que criou esta batida manual (esquecimento de marcação), se houver.
     *
     * @return HasOne<PointJustification, $this>
     */
    public function justification(): HasOne
    {
        return $this->hasOne(PointJustification::class, 'manual_point_id');
    }
}
