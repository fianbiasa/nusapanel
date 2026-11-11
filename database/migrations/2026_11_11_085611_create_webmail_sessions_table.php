<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Webmail sessions the panel opened for a mailbox (single sign-on): the
     * SHA-256 of a random token Roundcube signs in to Dovecot with, from
     * this server only, until the session expires.
     */
    public function up(): void
    {
        Schema::create('webmail_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mailbox_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webmail_sessions');
    }
};
