<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Every non-admin user belongs to exactly one college; deans, teachers
     * and learners are all scoped by it.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'dean', 'teacher', 'learner'])->default('learner')->after('id');
            $table->foreignId('college_id')->nullable()->after('role')->constrained()->restrictOnDelete();

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
            $table->dropColumn(['college_id', 'role']);
        });
    }
};
