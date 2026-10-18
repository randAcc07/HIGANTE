<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A batch is a split of one subject offering's learners (e.g. 2A-1 /
     * 2A-2), usually so each part fits its room. Batches belong to the
     * offering, not the section, so each subject can split differently;
     * reusing one subject's split for the others is a copy. Membership lives
     * on enrollments, so batches can be uneven.
     */
    public function up(): void
    {
        Schema::create('batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_offering_id')->constrained()->cascadeOnDelete();
            $table->string('name', 30);
            $table->timestamps();

            $table->unique(['subject_offering_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('batches');
    }
};
