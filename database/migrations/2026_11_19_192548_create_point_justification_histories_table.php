<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Histórico append-only das justificativas: uma linha por operação, com os valores
     * anteriores e novos. `manual_point_id` não tem chave estrangeira porque a batida
     * vinculada pode ser removida no cancelamento; o retrato dela fica nos valores.
     * O nome do responsável é copiado para continuar identificável se o usuário for removido.
     */
    public function up(): void
    {
        Schema::create('point_justification_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('point_justification_id')->constrained()->restrictOnDelete();
            $table->string('action', 32);
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('manual_point_id')->nullable();
            $table->date('affected_competence')->nullable();
            $table->boolean('closed_period')->default(false);
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('performed_by_name');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['point_justification_id', 'id'], 'point_justification_histories_order_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('point_justification_histories');
    }
};
