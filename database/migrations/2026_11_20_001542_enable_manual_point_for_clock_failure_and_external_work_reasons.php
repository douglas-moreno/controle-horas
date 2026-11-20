<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Motivos que criam batida manual (decisão do RH na F3).
     *
     * @var list<string>
     */
    private const REASONS = ['forgotten_punch', 'clock_failure', 'external_work_without_punch'];

    /**
     * Liga `creates_manual_point` somente nos três motivos aprovados, identificados pelo
     * `code`. Não toca nenhuma outra coluna nem outro motivo: as demais configurações do RH
     * são preservadas. Idempotente: rodar de novo não muda nada. Com o catálogo ainda
     * vazio, nada é alterado; o seeder já cria esses motivos com a opção ligada.
     */
    public function up(): void
    {
        DB::table('point_justification_reasons')
            ->whereIn('code', self::REASONS)
            ->where('creates_manual_point', false)
            ->update(['creates_manual_point' => true, 'updated_at' => now()]);
    }

    /**
     * Volta à configuração da F1: só o esquecimento de marcação cria batida.
     */
    public function down(): void
    {
        DB::table('point_justification_reasons')
            ->whereIn('code', ['clock_failure', 'external_work_without_punch'])
            ->where('creates_manual_point', true)
            ->update(['creates_manual_point' => false, 'updated_at' => now()]);
    }
};
