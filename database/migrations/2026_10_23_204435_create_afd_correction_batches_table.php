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
        Schema::create('afd_correction_batches', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);
            $table->string('status', 20);
            $table->char('manifest_sha256', 64);
            $table->char('afd_sha256', 64);
            $table->string('rep_serial', 17)->nullable();
            $table->unsignedInteger('expected_count');
            $table->unsignedInteger('applied_count')->default(0);
            $table->json('target');
            $table->string('applied_by');
            $table->timestamp('applied_at');
            $table->string('reverted_by')->nullable();
            $table->timestamp('reverted_at')->nullable();
            $table->timestamps();

            $table->index(['kind', 'manifest_sha256']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('afd_correction_batches');
    }
};
