<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One learner at one meeting on one date. status is always the teacher's
     * own mark; an approved excuse is linked rather than overwriting it, so
     * revoking the excuse restores the original absence. Only absences can
     * carry an excuse. Excuses are revoked by status, never deleted (MariaDB
     * also forbids a CHECK on a column whose foreign key sets NULL).
     */
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('offering_meeting_id')->constrained()->restrictOnDelete();
            $table->date('class_date');
            $table->enum('status', ['present', 'late', 'absent']);
            $table->foreignId('absence_excuse_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['enrollment_id', 'offering_meeting_id', 'class_date'], 'uq_attendance_records_meeting_date');
            $table->index(['offering_meeting_id', 'class_date']);
        });

        DB::statement("ALTER TABLE attendance_records ADD CONSTRAINT chk_attendance_records_excuse CHECK (absence_excuse_id IS NULL OR status = 'absent')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
