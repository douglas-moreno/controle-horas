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
        Schema::table('benefit_suggestion_runs', function (Blueprint $table) {
            $table->foreignId('tail_origin_period_id')->nullable()->after('processed_to')->constrained('benefit_periods')->nullOnDelete();
            $table->foreignId('tail_origin_run_id')->nullable()->after('tail_origin_period_id')->constrained('benefit_suggestion_runs')->nullOnDelete();
            $table->date('tail_origin_covered_until')->nullable()->after('tail_origin_run_id');
            $table->date('tail_from')->nullable()->after('tail_origin_covered_until');
            $table->date('tail_to')->nullable()->after('tail_from');
            $table->unsignedInteger('tail_pending_days')->default(0)->after('tail_to');
            $table->unsignedInteger('compensation_created')->default(0)->after('skipped_unobserved_days');
            $table->unsignedInteger('compensation_skipped_existing')->default(0)->after('compensation_created');
            $table->unsignedInteger('compensation_skipped_covered')->default(0)->after('compensation_skipped_existing');
            $table->unsignedInteger('compensation_skipped_unobserved_days')->default(0)->after('compensation_skipped_covered');
            $table->unsignedInteger('compensation_not_compensable')->default(0)->after('compensation_skipped_unobserved_days');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('benefit_suggestion_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tail_origin_run_id');
            $table->dropConstrainedForeignId('tail_origin_period_id');
            $table->dropColumn([
                'tail_origin_covered_until',
                'tail_from',
                'tail_to',
                'tail_pending_days',
                'compensation_created',
                'compensation_skipped_existing',
                'compensation_skipped_covered',
                'compensation_skipped_unobserved_days',
                'compensation_not_compensable',
            ]);
        });
    }
};
