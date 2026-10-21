<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('points', function (Blueprint $table) {
            $table->string('rep_serial', 17)->nullable()->after('type');
            $table->unsignedInteger('nsr')->nullable()->after('rep_serial');
            $table->foreignId('afd_import_id')->nullable()->after('nsr')->constrained()->nullOnDelete();

            $table->unique(['rep_serial', 'nsr']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('points', function (Blueprint $table) {
            $table->dropUnique(['rep_serial', 'nsr']);
            $table->dropConstrainedForeignId('afd_import_id');
            $table->dropColumn(['rep_serial', 'nsr']);
        });
    }
};
