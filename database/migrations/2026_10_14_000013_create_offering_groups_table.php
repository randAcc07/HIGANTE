<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A split of an offering's learners (e.g. 2A-1 / 2A-2) so each part fits
     * its room. Membership lives on enrollments, so splits can be uneven.
     */
    public function up(): void
    {
        Schema::create('offering_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_offering_id')->constrained()->cascadeOnDelete();
            $table->string('name', 30);
            $table->timestamps();

            $table->unique(['course_offering_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('offering_groups');
    }
};
