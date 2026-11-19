<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\JustificationTreatment;
use App\Enums\PunchKind;
use Database\Factories\PointJustificationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Justificativa do controle de ponto: registro independente das batidas, com efeito
 * imediato na apuração. Nunca é apagada; o cancelamento é lógico (`canceled_at`).
 * A batida manual criada por esquecimento de marcação fica em `manual_point_id`.
 */
class PointJustification extends Model
{
    /** @use HasFactory<PointJustificationFactory> */
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'reason_id',
        'occurrence_date',
        'start_time',
        'end_time',
        'ends_next_day',
        'duration_minutes',
        'punch_kind',
        'treatment',
        'allowed_minutes',
        'observation',
        'document_path',
        'document_original_name',
        'document_mime_type',
        'document_size',
        'document_sha256',
        'manual_point_id',
        'request_uuid',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurrence_date' => DateOnly::class,
            'ends_next_day' => 'boolean',
            'duration_minutes' => 'integer',
            'punch_kind' => PunchKind::class,
            'treatment' => JustificationTreatment::class,
            'allowed_minutes' => 'integer',
            'document_size' => 'integer',
            'version' => 'integer',
            'canceled_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function () {
            throw new LogicException('Justificativa do controle de ponto não pode ser apagada: use o cancelamento.');
        });
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<PointJustificationReason, $this>
     */
    public function reason(): BelongsTo
    {
        return $this->belongsTo(PointJustificationReason::class, 'reason_id');
    }

    /**
     * @return BelongsTo<Point, $this>
     */
    public function manualPoint(): BelongsTo
    {
        return $this->belongsTo(Point::class, 'manual_point_id');
    }

    /**
     * @return HasMany<PointJustificationHistory, $this>
     */
    public function histories(): HasMany
    {
        return $this->hasMany(PointJustificationHistory::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function canceler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'canceled_by');
    }

    /**
     * @param  Builder<PointJustification>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('canceled_at');
    }

    public function isCanceled(): bool
    {
        return $this->canceled_at !== null;
    }
}
