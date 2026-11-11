<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A mailbox's own spam filter: from which spam score (Rspamd's, as the
     * stars of X-Spam-Level) mail counts as spam, what happens to it (into
     * Junk, deleted, or only tagged), and senders that never or always
     * count as spam. Without a row the server's default applies: Rspamd's
     * "X-Spam: Yes" (score 6) into Junk.
     */
    public function up(): void
    {
        Schema::create('mail_spam_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mailbox_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('threshold')->default(6);
            $table->string('action', 8)->default('junk');
            $table->json('whitelist');
            $table->json('blacklist');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mail_spam_settings');
    }
};
