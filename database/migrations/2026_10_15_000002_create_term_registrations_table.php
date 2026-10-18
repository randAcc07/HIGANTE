<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A learner registered for one term by the registrar. year_level is the
     * level for this term (learners.year_level is the current one).
     *
     * section_id is set by auto-sectioning (see the sections migration for
     * how it picks a section) or by the dean; NULL means
     * the learner is waiting for the dean to place them. A regular learner
     * is enrolled in every offering of their section; irregular learners are
     * enrolled subject by subject. The section must belong to the same term
     * and program, which the application enforces.
     */
    public function up(): void
    {
        Schema::create('term_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learner_id')->constrained()->restrictOnDelete();
            $table->foreignId('term_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('year_level');
            $table->foreignId('section_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('is_irregular')->default(false);
            $table->enum('status', ['registered', 'withdrawn'])->default('registered');
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['learner_id', 'term_id']);
            $table->index(['term_id', 'section_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('term_registrations');
    }
};
