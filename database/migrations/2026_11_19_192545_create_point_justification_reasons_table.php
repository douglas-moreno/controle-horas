<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catálogo de motivos de justificativa do controle de ponto. `code` identifica o motivo
     * de forma estável (seeder e regras); nome, descrição e configurações são editáveis.
     * `allowed_treatments` nulo permite todos os tratamentos suportados.
     */
    public function up(): void
    {
        Schema::create('point_justification_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('category', 32);
            $table->text('description')->nullable();
            $table->string('time_mode', 32);
            $table->boolean('requires_document')->default(false);
            $table->string('default_treatment', 32)->nullable();
            $table->json('allowed_treatments')->nullable();
            $table->boolean('creates_manual_point')->default(false);
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['category', 'name']);
            $table->index(['active', 'category', 'sort_order'], 'point_justification_reasons_listing_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('point_justification_reasons');
    }
};
