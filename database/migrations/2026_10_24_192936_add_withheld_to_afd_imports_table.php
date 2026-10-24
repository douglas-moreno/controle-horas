<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marcações retidas por decisão do RH (não gravadas, contadas à parte) e responsável
     * informado no comando afd:import.
     */
    public function up(): void
    {
        Schema::table('afd_imports', function (Blueprint $table) {
            $table->string('operator')->nullable()->after('user_id');
            $table->unsignedInteger('withheld')->default(0)->after('rejected');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('afd_imports', function (Blueprint $table) {
            $table->dropColumn(['operator', 'withheld']);
        });
    }
};
