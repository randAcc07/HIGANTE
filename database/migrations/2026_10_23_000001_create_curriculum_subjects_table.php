<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A program's curriculum (prospectus): which of its college's subjects
     * are taken in which year level and semester. One curriculum per
     * program, no versions. It only fills new sections' subjects, so
     * changing it never touches existing sections.
     */
    public function up(): void
    {
        Schema::create('curriculum_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('year_level');
            $table->enum('semester', ['first', 'second', 'summer']);
            $table->timestamps();

            $table->unique(['program_id', 'subject_id']);
            $table->index(['program_id', 'year_level', 'semester']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('curriculum_subjects');
    }
};
