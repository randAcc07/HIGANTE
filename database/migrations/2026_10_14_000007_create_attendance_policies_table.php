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
     * One policy per term, so a handbook revision never rewrites past terms.
     * Counts are per enrollment (one class) per term:
     *   equivalent absences = unexcused absences + floor(lates / lates_per_absence)
     */
    public function up(): void
    {
        Schema::create('attendance_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('term_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('lates_per_absence')->default(3);
            $table->unsignedTinyInteger('drop_at_absences')->default(5);
            $table->json('warn_at_absences')->comment('Ascending equivalent-absence counts that trigger a warning, e.g. [3,4]');
            $table->timestamps();
        });

        DB::statement('ALTER TABLE attendance_policies ADD CONSTRAINT chk_attendance_policies_positive CHECK (lates_per_absence > 0 AND drop_at_absences > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_policies');
    }
};
