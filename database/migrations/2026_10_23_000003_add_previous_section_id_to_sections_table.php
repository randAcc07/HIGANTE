<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The section a section was copied from, in an earlier term (BSCS 2-A
     * of 2027-2028 from BSCS 1-A of 2026-2027's 2nd semester). Registration
     * follows it to keep a block together across terms. Null for sections
     * made by hand.
     */
    public function up(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->foreignId('previous_section_id')->nullable()->after('term_id')->constrained('sections')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('previous_section_id');
        });
    }
};
