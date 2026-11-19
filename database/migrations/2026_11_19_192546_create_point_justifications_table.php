<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Justificativas do controle de ponto: registro independente das batidas.
     *
     * - `manual_point_id`: batida manual criada pela justificativa (esquecimento); única,
     *   para que uma batida não pertença a duas justificativas.
     * - `request_uuid`: chave de idempotência do envio, contra gravação duplicada.
     * - `version`: controle otimista de edição concorrente.
     * - Cancelamento é lógico (`canceled_*`); a linha nunca é apagada.
     * - Documento: caminho no disco privado, nome original, tipo, tamanho e SHA-256.
     */
    public function up(): void
    {
        Schema::create('point_justifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('reason_id')->constrained('point_justification_reasons')->restrictOnDelete();
            $table->date('occurrence_date');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->boolean('ends_next_day')->default(false);
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->string('punch_kind', 32)->nullable();
            $table->string('treatment', 32);
            $table->unsignedInteger('allowed_minutes')->nullable();
            $table->text('observation')->nullable();
            $table->string('document_path')->nullable();
            $table->string('document_original_name')->nullable();
            $table->string('document_mime_type', 128)->nullable();
            $table->unsignedInteger('document_size')->nullable();
            $table->char('document_sha256', 64)->nullable();
            $table->foreignId('manual_point_id')->nullable()->unique()->constrained('points')->nullOnDelete();
            $table->uuid('request_uuid')->unique();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('canceled_at')->nullable();
            $table->foreignId('canceled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'occurrence_date', 'canceled_at'], 'point_justifications_employee_date_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('point_justifications');
    }
};
