<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * How many days after a class its teacher may still mark or correct it
     * (0: only on the day). Null, the default, is no limit. Deans can always
     * correct.
     */
    public function up(): void
    {
        Schema::table('attendance_policies', function (Blueprint $table) {
            $table->unsignedSmallInteger('teacher_edit_days')->nullable()->after('warn_at_absences');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_policies', function (Blueprint $table) {
            $table->dropColumn('teacher_edit_days');
        });
    }
};
