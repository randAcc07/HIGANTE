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
     * An excuse is decided class by class: each class the learner has
     * meetings in during its range gets its own decision, by a teacher of
     * that class (the dean for a class without one). The excuse's status
     * becomes a summary of its decisions, hence 'partial'.
     *
     * The excuse-level review columns stay until the demo seeder writes
     * decisions instead; a later migration drops them.
     */
    public function up(): void
    {
        Schema::create('excuse_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('absence_excuse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_offering_id')->constrained();
            $table->enum('status', ['pending', 'approved', 'declined', 'revoked'])->default('pending');
            $table->text('remarks')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revoke_remarks')->nullable();
            $table->timestamps();

            $table->unique(['absence_excuse_id', 'subject_offering_id']);
            $table->index(['subject_offering_id', 'status']);
        });

        DB::statement("ALTER TABLE absence_excuses MODIFY status ENUM('pending', 'approved', 'partial', 'rejected', 'revoked') NOT NULL DEFAULT 'pending'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE absence_excuses MODIFY status ENUM('pending', 'approved', 'rejected', 'revoked') NOT NULL DEFAULT 'pending'");
        Schema::dropIfExists('excuse_decisions');
    }
};
