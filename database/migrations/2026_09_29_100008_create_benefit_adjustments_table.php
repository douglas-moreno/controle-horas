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
        Schema::create('benefit_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('benefit_period_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('reason', 40);
            $table->string('source', 20);
            $table->string('status', 20);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedSmallInteger('days_count');
            $table->text('notes')->nullable();
            $table->string('dedupe_key', 191)->nullable()->unique();
            $table->foreignId('related_adjustment_id')->nullable()->constrained('benefit_adjustments')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['benefit_period_id', 'employee_id', 'status']);
            $table->index(['employee_id', 'status', 'starts_on', 'ends_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('benefit_adjustments');
    }
};
