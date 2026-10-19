<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A term's phase (upcoming, in session, ended) comes from its dates.
     * Closing is the one manual step: the admin closes an ended term once
     * its records are final, which makes it read-only.
     */
    public function up(): void
    {
        Schema::table('terms', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable()->after('ends_on');
            $table->foreignId('closed_by')->nullable()->after('closed_at')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('terms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn('closed_at');
        });
    }
};
