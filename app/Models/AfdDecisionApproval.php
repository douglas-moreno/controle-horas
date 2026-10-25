<?php

namespace App\Models;

use Database\Factories\AfdDecisionApprovalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Schema;
use LogicException;

/**
 * Aprovação de um manifesto de decisões do RH (`afd:decisions approve`), gravada depois
 * de autenticar o aprovador com e-mail e senha do sistema. Liga o usuário ao SHA-256 do
 * manifesto aprovado, do manifesto de origem e da simulação. O `afd:decisions apply` só
 * grava um manifesto que tenha aprovação registrada e ainda não usada por outro lote.
 *
 * Append-only: nunca editada nem apagada pelo Eloquent. Se o usuário for removido,
 * `user_id` fica nulo (pela chave estrangeira) e o nome e o e-mail copiados continuam.
 */
class AfdDecisionApproval extends Model
{
    /** @use HasFactory<AfdDecisionApprovalFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'approver_name',
        'approver_email',
        'manifest_sha256',
        'source_manifest_sha256',
        'preview_sha256',
        'rows',
        'target',
        'approved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rows' => 'integer',
            'target' => 'array',
            'approved_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Aprovação de decisões do RH não pode ser editada: o registro é append-only.');
        });

        static::deleting(function () {
            throw new LogicException('Aprovação de decisões do RH não pode ser apagada: o registro é append-only.');
        });
    }

    /**
     * A tabela existe (migration aplicada). Conferido uma vez por requisição.
     */
    public static function isAvailable(): bool
    {
        return once(fn () => Schema::hasTable((new self)->getTable()));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasOne<AfdCorrectionBatch, $this>
     */
    public function batch(): HasOne
    {
        return $this->hasOne(AfdCorrectionBatch::class);
    }
}
