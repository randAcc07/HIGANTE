<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Subjects are owned by one college and can be offered to any section of
     * that college's programs. A subject every college teaches (e.g.
     * English) is a separate subject per college.
     */
    public function up(): void
    {
        Schema::create('subjects', function (Blueprint $table) {
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
        Schema::dropIfExists('subjects');
    }
};
