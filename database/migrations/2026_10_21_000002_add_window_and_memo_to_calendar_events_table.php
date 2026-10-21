<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A suspension can cover part of each day (classes starting from and/or
     * before a time; both empty = whole days), and an event can carry the
     * memorandum that announced it.
     */
    public function up(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->time('suspends_from')->nullable()->after('suspends_classes');
            $table->time('suspends_until')->nullable()->after('suspends_from');
            $table->string('memo_number', 60)->nullable()->after('suspends_until');
            $table->string('memo_path')->nullable()->after('memo_number');
            $table->string('memo_name')->nullable()->after('memo_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->dropColumn(['suspends_from', 'suspends_until', 'memo_number', 'memo_path', 'memo_name']);
        });
    }
};
