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
        Schema::create('benefit_adjustment_impacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('benefit_adjustment_id')->constrained()->cascadeOnDelete();
            $table->string('benefit_type', 10);
            $table->smallInteger('quantity');
            $table->timestamps();

            $table->unique(['benefit_adjustment_id', 'benefit_type'], 'benefit_adjustment_impacts_adjustment_type_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('benefit_adjustment_impacts');
    }
};
