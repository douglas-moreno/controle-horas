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
        Schema::create('benefit_suggestion_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('benefit_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->date('last_imported_on');
            $table->date('horizon_on');
            $table->date('processed_from');
            $table->date('processed_to');
            $table->unsignedInteger('created')->default(0);
            $table->unsignedInteger('skipped_existing')->default(0);
            $table->unsignedInteger('skipped_covered')->default(0);
            $table->unsignedInteger('skipped_unobserved_days')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['benefit_period_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('benefit_suggestion_runs');
    }
};
