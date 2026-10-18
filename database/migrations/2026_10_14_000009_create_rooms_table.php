<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Rooms are campus-wide: managed by the admin, bookable by any dean.
     * capacity is optional and only ever warns (a section or batch bigger
     * than the room); it never blocks a booking.
     */
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('building', 100)->nullable();
            $table->tinyInteger('floor')->nullable();
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->enum('room_type', ['lecture', 'laboratory'])->default('lecture');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
