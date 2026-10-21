<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A suspension can be narrowed to some programs and/or year levels
     * (e.g. 4th years for a job fair): it then cancels only the classes of
     * matching sections. No programs and no year levels = everyone in its
     * scope, as before.
     */
    public function up(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->json('year_levels')->nullable()->after('suspends_until')->comment('Year levels whose sections it suspends, e.g. [4]; NULL = all');
        });

        Schema::create('calendar_event_program', function (Blueprint $table) {
            $table->foreignId('calendar_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('program_id')->constrained();

            $table->primary(['calendar_event_id', 'program_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calendar_event_program');

        Schema::table('calendar_events', function (Blueprint $table) {
            $table->dropColumn('year_levels');
        });
    }
};
