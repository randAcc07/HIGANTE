<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Admin accounts are kept on the server: an admin's email changed by
     * `php artisan app:admin-email` is audited as 'server' (no changed_by).
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE email_audits MODIFY method ENUM('self_service', 'admin', 'server') NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE email_audits MODIFY method ENUM('self_service', 'admin') NOT NULL");
    }
};
