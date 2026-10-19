<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Rooms people know by name (laboratories, the gym, an AVR) get one; it
     * becomes the room's label, with the code kept as the short identifier.
     * Unique when set; any number of rooms can have none.
     *
     * A third room type, venue, covers spaces that are neither lecture
     * rooms nor laboratories: the gym, the covered court, an AVR.
     */
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->string('name', 100)->nullable()->unique()->after('code');
            $table->enum('room_type', ['lecture', 'laboratory', 'venue'])->default('lecture')->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Migrations only change structure (the migrate user cannot touch rows),
     * so venues are not converted here: while any room is a venue, MariaDB
     * refuses to narrow the type and the rollback stops. Change those rooms'
     * type first.
     */
    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->enum('room_type', ['lecture', 'laboratory'])->default('lecture')->change();
            $table->dropUnique(['name']);
            $table->dropColumn('name');
        });
    }
};
