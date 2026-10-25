<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Aprovação registrada (AfdDecisionApproval) que autorizou um lote de decisões do RH.
     * Nula nos lotes de outros tipos e nos anteriores à exigência de aprovação.
     */
    public function up(): void
    {
        Schema::table('afd_correction_batches', function (Blueprint $table) {
            $table->foreignId('afd_decision_approval_id')->nullable()->unique()->after('target')->constrained()->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('afd_correction_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('afd_decision_approval_id');
        });
    }
};
