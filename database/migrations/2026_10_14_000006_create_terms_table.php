<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('terms', function (Blueprint $table) {
            $table->id();
            $table->char('school_year', 9);
            $table->enum('semester', ['first', 'second', 'summer']);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamps();

            $table->unique(['school_year', 'semester']);
        });

        DB::statement('ALTER TABLE terms ADD CONSTRAINT chk_terms_dates CHECK (ends_on > starts_on)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('terms');
    }
};
