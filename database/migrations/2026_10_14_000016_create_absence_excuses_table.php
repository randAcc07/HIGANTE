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
     * A request to excuse a learner's absences over a date range. The dean
     * of the learner's college reviews it; once approved it covers the
     * learner's absences in every class in that range. Lates are never
     * excused. Supporting files live in absence_excuse_documents.
     *
     * reason:
     *   medical         - physician-signed medical certificate; the
     *                     application refuses approval without a document
     *   illness         - sickness without a certificate (e.g. a guardian's
     *                     letter); approval is at the dean's discretion
     *   school_activity - official school activity
     *   other
     */
    public function up(): void
    {
        Schema::create('absence_excuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learner_id')->constrained()->restrictOnDelete();
            $table->date('date_from');
            $table->date('date_to');
            $table->enum('reason', ['medical', 'illness', 'school_activity', 'other']);
            $table->text('remarks')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_remarks')->nullable();
            $table->timestamps();

            $table->index(['learner_id', 'status']);
        });

        DB::statement('ALTER TABLE absence_excuses ADD CONSTRAINT chk_absence_excuses_dates CHECK (date_to >= date_from)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('absence_excuses');
    }
};
