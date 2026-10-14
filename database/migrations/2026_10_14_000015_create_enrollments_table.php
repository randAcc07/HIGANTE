<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A learner in one class. Hitting the policy's drop count only flags the
     * enrollment (for_dropping); the dean confirms the drop.
     * warning_level is the highest warning count already sent, so a warning
     * goes out once per threshold.
     */
    public function up(): void
    {
        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learner_id')->constrained()->restrictOnDelete();
            $table->foreignId('course_offering_id')->constrained()->restrictOnDelete();
            $table->foreignId('offering_group_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('status', ['enrolled', 'for_dropping', 'dropped'])->default('enrolled');
            $table->unsignedTinyInteger('warning_level')->default(0);
            $table->timestamp('flagged_for_drop_at')->nullable();
            $table->timestamp('drop_confirmed_at')->nullable();
            $table->foreignId('drop_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['learner_id', 'course_offering_id']);
            $table->index(['course_offering_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('enrollments');
    }
};
