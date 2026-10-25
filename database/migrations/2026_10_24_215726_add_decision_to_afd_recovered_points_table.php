<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vínculo opcional da recuperação com a decisão do RH que a autorizou (recuperações
     * por decisão, fora da classe C2). Nulo nas recuperações dos lotes de correção.
     */
    public function up(): void
    {
        Schema::table('afd_recovered_points', function (Blueprint $table) {
            $table->foreignId('afd_historical_nsr_decision_id')->nullable()->after('afd_correction_batch_id')->constrained()->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('afd_recovered_points', function (Blueprint $table) {
            $table->dropConstrainedForeignId('afd_historical_nsr_decision_id');
        });
    }
};
