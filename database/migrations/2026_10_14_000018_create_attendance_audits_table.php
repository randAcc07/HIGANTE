<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Every change to an attendance mark or its excuse, so a disputed drop
     * flag can be traced. old_status is NULL for the first mark.
     */
    public function up(): void
    {
        Schema::create('attendance_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_record_id')->constrained()->cascadeOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('old_status', ['present', 'late', 'absent'])->nullable();
            $table->enum('new_status', ['present', 'late', 'absent']);
            $table->unsignedBigInteger('old_absence_excuse_id')->nullable();
            $table->unsignedBigInteger('new_absence_excuse_id')->nullable();
            $table->timestamp('changed_at')->useCurrent();

            $table->index(['attendance_record_id', 'changed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_audits');
    }
};
