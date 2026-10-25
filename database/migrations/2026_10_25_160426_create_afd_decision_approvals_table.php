<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Aprovações de manifestos de decisões do RH (append-only): quem aprovou, autenticado
     * com as credenciais do sistema, e os SHA-256 do manifesto aprovado, do manifesto de
     * origem e da simulação. Nome e e-mail ficam copiados para a aprovação continuar
     * identificável se o usuário for removido.
     */
    public function up(): void
    {
        Schema::create('afd_decision_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('approver_name');
            $table->string('approver_email');
            $table->char('manifest_sha256', 64)->unique();
            $table->char('source_manifest_sha256', 64);
            $table->char('preview_sha256', 64);
            $table->unsignedInteger('rows');
            $table->json('target');
            $table->timestamp('approved_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('afd_decision_approvals');
    }
};
