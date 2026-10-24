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
        Schema::create('afd_point_quarantines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('afd_correction_batch_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('original_id');
            $table->bigInteger('pis')->nullable();
            $table->date('date')->nullable();
            $table->time('time')->nullable();
            $table->string('type')->nullable();
            $table->string('rep_serial', 17)->nullable();
            $table->unsignedInteger('nsr')->nullable();
            $table->unsignedBigInteger('afd_import_id')->nullable();
            $table->timestamp('original_created_at')->nullable();
            $table->timestamp('original_updated_at')->nullable();
            $table->char('row_hash', 64);
            $table->unsignedInteger('afd_line');
            $table->unsignedInteger('afd_nsr')->nullable();
            $table->char('afd_record_type', 1);
            $table->string('criterion');
            $table->string('status', 20);
            $table->string('restored_by')->nullable();
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();

            $table->unique(['afd_correction_batch_id', 'original_id']);
            $table->index(['original_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('afd_point_quarantines');
    }
};
