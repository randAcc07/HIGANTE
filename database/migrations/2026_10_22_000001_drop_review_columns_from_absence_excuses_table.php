<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Excuses are decided class by class (excuse_decisions); the columns
     * from when the dean decided a whole excuse go.
     */
    public function up(): void
    {
        Schema::table('absence_excuses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropConstrainedForeignId('revoked_by');
            $table->dropColumn(['reviewed_at', 'review_remarks', 'revoked_at', 'revoke_remarks']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('absence_excuses', function (Blueprint $table) {
            $table->foreignId('reviewed_by')->nullable()->after('submitted_by')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->text('review_remarks')->nullable()->after('reviewed_at');
            $table->timestamp('revoked_at')->nullable()->after('review_remarks');
            $table->foreignId('revoked_by')->nullable()->after('revoked_at')->constrained('users')->nullOnDelete();
            $table->text('revoke_remarks')->nullable()->after('revoked_by');
        });
    }
};
