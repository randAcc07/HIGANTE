<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Roles are exclusive. Deans, teachers and learners belong to exactly one
     * college and are scoped by it; the admin and the registrar are
     * campus-wide and have none.
     *
     * Accounts are deactivated, never deleted: an inactive user cannot log
     * in, and their records stay.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'registrar', 'dean', 'teacher', 'learner'])->default('learner')->after('id');
            $table->foreignId('college_id')->nullable()->after('role')->constrained()->restrictOnDelete();
            $table->boolean('is_active')->default(true)->after('college_id');

            $table->index(['college_id', 'role']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['college_id']);
            $table->dropIndex(['college_id', 'role']);
            $table->dropColumn(['is_active', 'college_id', 'role']);
        });
    }
};
