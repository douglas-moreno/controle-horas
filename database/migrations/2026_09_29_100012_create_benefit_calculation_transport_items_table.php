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
        Schema::create('benefit_calculation_transport_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('benefit_calculation_id')->constrained(indexName: 'benefit_calc_transport_items_calculation_id_foreign')->cascadeOnDelete();
            $table->foreignId('transport_route_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('transport_fare_id')->nullable()->constrained()->nullOnDelete();
            $table->string('fare_name', 100);
            $table->decimal('fare_amount', 10, 2);
            $table->unsignedTinyInteger('trips_per_day');
            $table->decimal('daily_amount', 10, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('benefit_calculation_transport_items');
    }
};
