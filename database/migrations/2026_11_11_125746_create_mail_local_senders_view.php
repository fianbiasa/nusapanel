<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Which envelope senders each hosting account's Linux user may give the
     * local sendmail (PHP's mail(), cron, Dovecot's automatic replies), as
     * Postfix's local_login_sender_maps reads them: its own login name, the
     * empty sender of automatic replies, and any address at its domains.
     * Mail is only DKIM-signed for the domain it is sent from, so an
     * account cannot have mail signed for another account's domain.
     */
    public function up(): void
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        $senders = $sqlite
            ? "u.username || ' <> ' || group_concat('@' || d.name, ' ')"
            : "CONCAT(u.username, ' <> ', GROUP_CONCAT(CONCAT('@', d.name) ORDER BY d.name SEPARATOR ' '))";

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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_mail_local_senders');
    }
};
