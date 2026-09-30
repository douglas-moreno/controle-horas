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
        Schema::create('benefit_calculations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('benefit_period_employee_id')->constrained()->cascadeOnDelete();
            $table->string('benefit_type', 10);
            $table->unsignedSmallInteger('base_days');
            $table->unsignedSmallInteger('positive_days');
            $table->unsignedSmallInteger('negative_days');
            $table->unsignedSmallInteger('carried_in_days')->default(0);
            $table->foreignId('carried_from_calculation_id')->nullable()->unique()->constrained('benefit_calculations')->restrictOnDelete();
            $table->smallInteger('raw_days');
            $table->unsignedSmallInteger('final_days');
            $table->unsignedSmallInteger('carried_out_days')->default(0);
            $table->decimal('unit_amount', 10, 2);
            $table->decimal('total_amount', 12, 2);
            $table->foreignId('benefit_rate_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['benefit_period_employee_id', 'benefit_type'], 'benefit_calculations_period_employee_type_unique');
            $table->index(['benefit_type', 'carried_out_days']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('benefit_calculations');
    }
};
