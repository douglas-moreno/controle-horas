<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F5.3 (D10): dias sem sugestão de ausência por justificativa de ponto abonada. Execuções
 * anteriores recebem 0 (não havia supressão por justificativa).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('benefit_suggestion_runs', function (Blueprint $table) {
            $table->unsignedInteger('skipped_justified')->default(0)->after('skipped_covered');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('benefit_suggestion_runs', function (Blueprint $table) {
            $table->dropColumn('skipped_justified');
        });
    }
};
