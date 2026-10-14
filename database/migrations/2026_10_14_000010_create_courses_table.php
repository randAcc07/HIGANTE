<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Courses are owned by one college; shared subjects (e.g. English) are
     * separate courses per college.
     */
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->restrictOnDelete();
            $table->string('code', 20);
            $table->string('title', 150);
            $table->decimal('lecture_units', 3, 1)->default(0);
            $table->decimal('laboratory_units', 3, 1)->default(0);
            $table->timestamps();

            $table->unique(['college_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
