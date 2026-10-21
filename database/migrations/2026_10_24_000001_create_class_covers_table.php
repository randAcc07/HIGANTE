<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A substitute covering one class for a range of dates (a teacher on
     * leave). The class keeps its teacher: the substitute may also see and
     * mark its meetings in the range, while excuses stay with the teacher.
     * Ranges of one class don't overlap (checked in the app).
     */
    public function up(): void
    {
        Schema::create('class_covers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('substitute_id')->constrained('teachers')->restrictOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['schedule_id', 'starts_on']);
            $table->index(['substitute_id', 'starts_on', 'ends_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('class_covers');
    }
};
