<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Revoking an approved excuse is its own status, so the history shows it
     * was once approved; the absences it excused count again.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE absence_excuses MODIFY status ENUM('pending', 'approved', 'rejected', 'revoked') NOT NULL DEFAULT 'pending'");

        Schema::table('absence_excuses', function (Blueprint $table) {
            $table->timestamp('revoked_at')->nullable()->after('review_remarks');
            $table->foreignId('revoked_by')->nullable()->after('revoked_at')->constrained('users')->nullOnDelete();
            $table->text('revoke_remarks')->nullable()->after('revoked_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('absence_excuses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('revoked_by');
            $table->dropColumn(['revoked_at', 'revoke_remarks']);
        });

        DB::statement("ALTER TABLE absence_excuses MODIFY status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending'");
    }
};
