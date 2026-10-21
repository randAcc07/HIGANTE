<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The dean's review of an enrollment flagged for dropping: why a class
     * was dropped, a dismissed flag (held until the learner's equivalent
     * absences pass the count it was dismissed at) and a reinstated drop.
     */
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->enum('drop_reason', ['absences', 'withdrawn', 'removed'])->nullable()->after('drop_confirmed_by');
            $table->timestamp('drop_dismissed_at')->nullable()->after('drop_reason');
            $table->foreignId('drop_dismissed_by')->nullable()->after('drop_dismissed_at')->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('drop_dismissed_absences')->nullable()->after('drop_dismissed_by');
            $table->timestamp('reinstated_at')->nullable()->after('drop_dismissed_absences');
            $table->foreignId('reinstated_by')->nullable()->after('reinstated_at')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reinstated_by');
            $table->dropConstrainedForeignId('drop_dismissed_by');
            $table->dropColumn(['drop_reason', 'drop_dismissed_at', 'drop_dismissed_absences', 'reinstated_at']);
        });
    }
};
