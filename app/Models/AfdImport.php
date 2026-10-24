<?php

namespace App\Models;

use Database\Factories\AfdImportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Importação de um AFD concluída. Só existe para arquivos aceitos: um arquivo recusado
 * não grava nada. Os cursores são NSRs do REP:
 * - `cursor_before`: maior NSR já examinado antes desta importação;
 * - `cursor_after`: maior NSR examinado ao fim (igual a `max_nsr`);
 * - `max_nsr`: maior NSR do arquivo (qualquer tipo);
 * - `max_valid_punch_nsr`: maior NSR de marcação válida do arquivo;
 * - `max_imported_nsr`: maior NSR efetivamente gravado em `points` por esta importação.
 * As marcações rejeitadas ficam em `afd_import_rejections`, com NSR e motivo. As retidas
 * por decisão do RH (`afd_historical_nsrs`, classificação R) não são gravadas: contam só
 * em `withheld`. `operator` é o responsável informado no comando afd:import.
 */
class AfdImport extends Model
{
    /** @use HasFactory<AfdImportFactory> */
    use HasFactory;

    /**
     * Não há coluna updated_at: a conclusão é registrada em `completed_at`.
     */
    public const UPDATED_AT = null;

    public const TRANSITION_FRESH = 'fresh';

    public const TRANSITION_LEGACY = 'legacy';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'file_starts_on' => 'immutable_date',
            'file_ends_on' => 'immutable_date',
            'file_generated_at' => 'immutable_datetime',
            'legacy_line_number' => 'integer',
            'legacy_checked' => 'integer',
            'legacy_confirmed' => 'integer',
            'cursor_before' => 'integer',
            'cursor_after' => 'integer',
            'first_nsr' => 'integer',
            'max_nsr' => 'integer',
            'max_valid_punch_nsr' => 'integer',
            'max_imported_nsr' => 'integer',
            'company_records' => 'integer',
            'punch_records' => 'integer',
            'clock_adjustment_records' => 'integer',
            'employee_records' => 'integer',
            'imported' => 'integer',
            'existing' => 'integer',
            'already_processed' => 'integer',
            'rejected' => 'integer',
            'withheld' => 'integer',
            'created_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<AfdRep, $this>
     */
    public function rep(): BelongsTo
    {
        return $this->belongsTo(AfdRep::class, 'afd_rep_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<AfdImportRejection, $this>
     */
    public function rejections(): HasMany
    {
        return $this->hasMany(AfdImportRejection::class);
    }

    /**
     * @return HasMany<Point, $this>
     */
    public function points(): HasMany
    {
        return $this->hasMany(Point::class);
    }
}
