<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Histórico append-only das anotações do RH sobre pontos importados do AFD (com REP e
     * NSR): desconsiderar administrativamente ou batida duplicada de outro ponto. Não altera
     * `points`. No máximo uma anotação ativa por ponto (`active_key`, coluna gerada única).
     * Os pontos são referenciados por id e hash, sem chave estrangeira: o histórico não pode
     * impedir para sempre a exclusão de um ponto depois da revogação; a proteção do ponto
     * com anotação ativa fica na aplicação (Point::isAnnotatedByHr).
     */
    public function up(): void
    {
        Schema::create('afd_point_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('afd_correction_batch_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('point_id');
            $table->string('rep_serial', 17);
            $table->unsignedBigInteger('nsr');
            $table->unsignedInteger('afd_line');
            $table->char('afd_line_sha256', 64);
            $table->char('point_hash', 64);
            $table->string('decision', 20);
            $table->unsignedBigInteger('duplicate_of_point_id')->nullable()->index();
            $table->char('duplicate_of_point_hash', 64)->nullable();
            $table->string('decided_by');
            $table->string('decided_role')->nullable();
            $table->timestamp('decided_at');
            $table->text('justification');
            $table->string('evidence_type', 30)->nullable();
            $table->string('evidence_reference')->nullable();
            $table->char('evidence_sha256', 64)->nullable();
            $table->foreignId('supersedes_decision_id')->nullable()->unique()->constrained('afd_point_decisions')->restrictOnDelete();
            $table->string('status', 20);
            $table->unsignedBigInteger('active_key')->nullable()->storedAs("case when `status` = 'active' then `point_id` end")->unique();
            $table->timestamps();

            $table->index(['rep_serial', 'nsr']);
            $table->index(['point_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('afd_point_decisions');
    }
};
