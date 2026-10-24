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
        Schema::create('afd_historical_nsrs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('afd_correction_batch_id')->constrained()->restrictOnDelete();
            $table->string('rep_serial', 17);
            $table->unsignedInteger('nsr');
            $table->unsignedInteger('afd_line');
            $table->char('afd_line_sha256', 64);
            $table->string('classification', 10);
            $table->text('evidence');
            $table->unsignedBigInteger('related_manual_point_id')->nullable();
            $table->string('status', 30);
            $table->unsignedBigInteger('recovered_point_id')->nullable();
            $table->string('decided_by')->nullable();
            $table->text('decision_justification')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['rep_serial', 'nsr']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('afd_historical_nsrs');
    }
};
