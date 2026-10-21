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
        Schema::create('afd_import_rejections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('afd_import_id')->constrained()->cascadeOnDelete();
            $table->foreignId('afd_rep_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('nsr');
            $table->string('reason', 40);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['afd_rep_id', 'nsr']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('afd_import_rejections');
    }
};
