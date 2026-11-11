<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How much mail each mailbox holds, as Dovecot's quota bookkeeping
     * last reported it (rayxo:collect-usage); null until measured.
     */
    public function up(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->unsignedBigInteger('used_kb')->nullable()->after('daily_send_limit');
            $table->unsignedInteger('message_count')->nullable()->after('used_kb');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropColumn(['used_kb', 'message_count']);
        });
    }
};
