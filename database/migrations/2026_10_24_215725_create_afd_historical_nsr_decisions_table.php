<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Histórico append-only das decisões do RH sobre pendências históricas do AFD. No
     * máximo uma decisão ativa por pendência (`active_key`, coluna gerada única).
     */
    public function up(): void
    {
        Schema::create('afd_historical_nsr_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('afd_historical_nsr_id')->constrained()->restrictOnDelete();
            $table->foreignId('afd_correction_batch_id')->constrained()->restrictOnDelete();
            $table->string('decision', 20);
            $table->string('status_before', 30);
            $table->string('status_after', 30);
            $table->unsignedBigInteger('related_manual_point_id')->nullable();
            $table->unsignedBigInteger('recovered_point_id')->nullable();
            $table->string('decided_by');
            $table->string('decided_role')->nullable();
            $table->timestamp('decided_at');
            $table->text('justification');
            $table->string('evidence_type', 30)->nullable();
            $table->string('evidence_reference')->nullable();
            $table->char('evidence_sha256', 64)->nullable();
            $table->json('expected_consequence');
            $table->char('expected_consequence_sha256', 64);
            $table->json('acknowledged_warnings')->nullable();
            $table->date('review_due_on')->nullable();
            $table->text('review_notes')->nullable();
            $table->foreignId('supersedes_decision_id')->nullable()->unique()->constrained('afd_historical_nsr_decisions')->restrictOnDelete();
            $table->string('status', 20);
            $table->unsignedBigInteger('active_key')->nullable()->storedAs("case when `status` = 'active' then `afd_historical_nsr_id` end")->unique();
            $table->timestamps();

            $table->index(['afd_historical_nsr_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('afd_historical_nsr_decisions');
    }
};
