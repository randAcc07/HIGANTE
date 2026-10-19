<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A user's pending request to change their email. It takes effect only
     * once the new address has confirmed and the old address has approved,
     * in either order. One pending request per user; a new request replaces
     * it. The emailed links are signed URLs that expire with the request, so
     * no tokens are stored; deleting the row voids its links.
     */
    public function up(): void
    {
        Schema::create('email_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('new_email');
            $table->timestamp('expires_at');
            $table->timestamp('new_confirmed_at')->nullable();
            $table->timestamp('old_approved_at')->nullable();
            $table->timestamps();

            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_changes');
    }
};
