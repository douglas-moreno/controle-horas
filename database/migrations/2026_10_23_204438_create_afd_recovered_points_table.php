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
        Schema::create('afd_recovered_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('afd_correction_batch_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('point_id');
            $table->string('rep_serial', 17);
            $table->unsignedInteger('nsr');
            $table->unsignedInteger('afd_line');
            $table->char('afd_line_sha256', 64);
            $table->char('point_hash', 64);
            $table->string('status', 20);
            $table->string('reverted_by')->nullable();
            $table->timestamp('reverted_at')->nullable();
            $table->timestamps();

            $table->unique(['afd_correction_batch_id', 'nsr']);
            $table->index(['rep_serial', 'nsr', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('afd_recovered_points');
    }
};
