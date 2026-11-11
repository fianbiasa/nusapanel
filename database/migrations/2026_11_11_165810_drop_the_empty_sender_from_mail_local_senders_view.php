<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The local sendmail no longer takes the empty sender (<>) from hosting
     * accounts: mail without a sender names no account, so it would escape
     * the daily send limit. Automatic replies now go out from the mailbox's
     * address (sieve_vacation_send_from_recipient), and the other local mail
     * of an account (PHP's mail(), cron) has its login name or an address at
     * its domains, as before.
     */
    public function up(): void
    {
        $this->createView(withEmptySender: false);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->createView(withEmptySender: true);
    }

    private function createView(bool $withEmptySender): void
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        $separator = $withEmptySender ? ' <> ' : ' ';
        $senders = $sqlite
            ? "u.username || '{$separator}' || group_concat('@' || d.name, ' ')"
            : "CONCAT(u.username, '{$separator}', GROUP_CONCAT(CONCAT('@', d.name) ORDER BY d.name SEPARATOR ' '))";

        DB::statement('DROP VIEW IF EXISTS v_mail_local_senders');

        DB::statement(<<<SQL
            CREATE VIEW v_mail_local_senders AS
            SELECT u.username AS login, {$senders} AS senders
            FROM users u
            JOIN domains d ON d.user_id = u.id
            WHERE u.role = 'user'
            GROUP BY u.id, u.username
            SQL);
    }
};
