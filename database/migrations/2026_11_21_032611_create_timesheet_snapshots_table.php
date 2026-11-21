<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Snapshots append-only do espelho de ponto, um registro por funcionário, dia e
     * versão (F6.2a). Guardam as entradas do cálculo (`inputs`, com `inputs_hash`) e o
     * resultado do dia como foi apresentado (`result`, com `result_hash`), para consultar
     * a versão histórica sem recalcular.
     *
     * - `sequence`: versão do dia do funcionário (1, 2, 3...), única com funcionário e data.
     * - `operation_uuid`: agrupa os snapshots de uma mesma operação (antes e depois, várias datas).
     * - Funcionário, competência e histórico de justificativa com `restrictOnDelete`: o
     *   snapshot impede a exclusão do que referencia. O usuário pode ser removido
     *   (`nullOnDelete`); o nome copiado continua.
     * - `record_hash`: SHA-256 das colunas gravadas (exceto o usuário, que a chave
     *   estrangeira pode anular), para detectar alteração feita direto no banco.
     * - Sem triggers: com binlog ligado, o MySQL exige SUPER (ou
     *   `log_bin_trust_function_creators`) para criá-los, e o usuário da aplicação não tem.
     *   A recusa de UPDATE/DELETE fica no model e no builder; no banco, depende de GRANT.
     */
    public function up(): void
    {
        Schema::create('timesheet_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->unsignedInteger('sequence');
            $table->string('source', 32);
            $table->date('competence');
            $table->foreignId('benefit_period_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('point_justification_history_id')->nullable()->constrained()->restrictOnDelete();
            $table->uuid('operation_uuid')->nullable();
            $table->unsignedSmallInteger('schema_version');
            $table->string('rule_version', 64);
            $table->char('rules_hash', 64);
            $table->json('inputs');
            $table->char('inputs_hash', 64);
            $table->json('result');
            $table->char('result_hash', 64);
            $table->char('record_hash', 64);
            $table->string('reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['employee_id', 'date', 'sequence'], 'timesheet_snapshots_version_unique');
            $table->index(['employee_id', 'date', 'source'], 'timesheet_snapshots_source_index');
            $table->index(['competence', 'employee_id'], 'timesheet_snapshots_competence_index');
            $table->index('operation_uuid');
        });
    }

    /**
     * Reverse the migrations.
     */
    /**
     * Reverte só com a tabela vazia. Com snapshots gravados, o histórico permanente não é
     * apagado: o rollback (`migrate:rollback`, `migrate:reset`, `migrate:refresh`) é
     * interrompido com exceção antes de qualquer DDL, sem depender do ambiente.
     * `migrate:fresh` não chama este método (apaga todas as tabelas) e continua servindo aos
     * ambientes descartáveis e aos testes.
     */
    public function down(): void
    {
        if (Schema::hasTable('timesheet_snapshots') && DB::table('timesheet_snapshots')->exists()) {
            throw new RuntimeException('Rollback recusado: timesheet_snapshots contém o histórico permanente do espelho de ponto. Em ambiente descartável, use migrate:fresh; em produção, a remoção exige backup e decisão do administrador do banco.');
        }

        Schema::dropIfExists('timesheet_snapshots');
    }
};
