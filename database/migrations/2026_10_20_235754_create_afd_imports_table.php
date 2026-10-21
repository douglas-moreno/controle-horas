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
        Schema::create('afd_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('afd_rep_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->char('file_hash', 64);
            $table->unsignedBigInteger('file_size');
            $table->date('file_starts_on');
            $table->date('file_ends_on');
            $table->dateTime('file_generated_at');
            $table->string('transition', 20)->nullable();
            $table->unsignedInteger('legacy_line_number')->nullable();
            $table->unsignedInteger('legacy_checked')->default(0);
            $table->unsignedInteger('legacy_confirmed')->default(0);
            $table->unsignedInteger('cursor_before');
            $table->unsignedInteger('cursor_after');
            $table->unsignedInteger('first_nsr')->nullable();
            $table->unsignedInteger('max_nsr')->nullable();
            $table->unsignedInteger('max_valid_punch_nsr')->nullable();
            $table->unsignedInteger('max_imported_nsr')->nullable();
            $table->unsignedInteger('company_records')->default(0);
            $table->unsignedInteger('punch_records')->default(0);
            $table->unsignedInteger('clock_adjustment_records')->default(0);
            $table->unsignedInteger('employee_records')->default(0);
            $table->unsignedInteger('imported')->default(0);
            $table->unsignedInteger('existing')->default(0);
            $table->unsignedInteger('already_processed')->default(0);
            $table->unsignedInteger('rejected')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();

            $table->index(['afd_rep_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('afd_imports');
    }
};
