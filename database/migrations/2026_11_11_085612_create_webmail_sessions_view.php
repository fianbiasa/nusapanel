<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * What Dovecot reads to let Roundcube sign in with a webmail session's
     * token instead of the mailbox's password: the address and the token's
     * SHA-256, for sessions that have not expired, of mailboxes Dovecot
     * would sign in anyway (v_mail_mailboxes). Expiry times are stored in
     * UTC, whatever time zone MariaDB itself runs in.
     */
    public function up(): void
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        $now = $sqlite ? "datetime('now')" : 'UTC_TIMESTAMP()';
        $address = $sqlite ? "m.local_part || '@' || d.name" : "CONCAT(m.local_part, '@', d.name)";

        DB::statement('DROP VIEW IF EXISTS v_mail_webmail_sessions');

        DB::statement(<<<SQL
            CREATE VIEW v_mail_webmail_sessions AS
            SELECT v.address AS address, s.token_hash AS token_hash
            FROM webmail_sessions s
            JOIN mailboxes m ON m.id = s.mailbox_id
            JOIN mail_domains md ON md.id = m.mail_domain_id
            JOIN domains d ON d.id = md.domain_id
            JOIN v_mail_mailboxes v ON v.address = {$address}
            WHERE s.expires_at > {$now}
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_mail_webmail_sessions');
    }
};
