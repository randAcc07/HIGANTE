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
     * One weekly slot of a subject offering (e.g. Statics, 2A, Mon
     * 08:00-10:00, Room 301). A NULL batch means the whole offering meets;
     * otherwise only that batch's learners.
     *
     * starts_at/ends_at sit on a fixed reference week so the weekday is part
     * of the value: 2001-01-01 is a Monday, ..., 2001-01-07 is a Sunday.
     * MariaDB periods cannot use TIME columns, so this lets the database
     * reject overlapping room and teacher bookings itself (WITHOUT OVERLAPS).
     * Periods are half-open, so back-to-back slots do not conflict.
     *
     * term_id duplicates the section's term so the overlap keys can be
     * scoped per term. teacher_id is always filled (NULLs never conflict in
     * a unique key). An online schedule has no room.
     *
     * Section/batch and per-learner conflicts depend on enrollments and are
     * checked in the application.
     */
    public function up(): void
    {
        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_offering_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->constrained()->restrictOnDelete();
            $table->foreignId('teacher_id')->constrained()->restrictOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->restrictOnDelete();
            $table->enum('mode', ['onsite', 'online'])->default('onsite');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE schedules
                ADD CONSTRAINT chk_schedules_reference_week
                    CHECK (starts_at >= '2001-01-01 00:00:00' AND ends_at < '2001-01-08 00:00:00'),
                ADD CONSTRAINT chk_schedules_same_day
                    CHECK (DATE(starts_at) = DATE(ends_at)),
                ADD CONSTRAINT chk_schedules_room
                    CHECK (room_id IS NOT NULL OR mode = 'online'),
                ADD PERIOD FOR slot (starts_at, ends_at),
                ADD UNIQUE KEY uq_schedules_room_slot (term_id, room_id, slot WITHOUT OVERLAPS),
                ADD UNIQUE KEY uq_schedules_teacher_slot (term_id, teacher_id, slot WITHOUT OVERLAPS)
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};
