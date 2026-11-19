<?php

namespace App\Models;

use App\Enums\JustificationCategory;
use App\Enums\JustificationTimeMode;
use App\Enums\JustificationTreatment;
use Database\Factories\PointJustificationReasonFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Motivo do catálogo de justificativas do controle de ponto. Configurável pelo RH:
 * nome, descrição, ativação, tratamentos permitidos e exigência de documento.
 */
class PointJustificationReason extends Model
{
    /** @use HasFactory<PointJustificationReasonFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'category',
        'description',
        'time_mode',
        'requires_document',
        'default_treatment',
        'allowed_treatments',
        'creates_manual_point',
        'active',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => JustificationCategory::class,
            'time_mode' => JustificationTimeMode::class,
            'default_treatment' => JustificationTreatment::class,
            'allowed_treatments' => 'array',
            'requires_document' => 'boolean',
            'creates_manual_point' => 'boolean',
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<PointJustification, $this>
     */
    public function justifications(): HasMany
    {
        return $this->hasMany(PointJustification::class, 'reason_id');
    }

    /**
     * @param  Builder<PointJustificationReason>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    /**
     * Tratamentos que o motivo aceita, já sem os não suportados pelo sistema. Lista
     * nula no catálogo significa todos os suportados.
     *
     * @return list<JustificationTreatment>
     */
    public function treatments(): array
    {
        $configured = $this->allowed_treatments === null
            ? JustificationTreatment::cases()
            : array_values(array_filter(array_map(fn (string $value) => JustificationTreatment::tryFrom($value), $this->allowed_treatments)));

        return array_values(array_filter($configured, fn (JustificationTreatment $treatment) => $treatment->isSupported()));
    }

    public function allowsTreatment(JustificationTreatment $treatment): bool
    {
        return in_array($treatment, $this->treatments(), true);
    }
}
