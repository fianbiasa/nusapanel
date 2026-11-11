<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Which Sieve script Dovecot runs before a mailbox's own, as a userdb
     * field: the server's spam script (spam into Junk), or an empty one for
     * a mailbox with its own spam filter, whose script then handles spam
     * itself (threshold, whitelist, tag only).
     */
    public function up(): void
    {
        $this->createViews(withSieveBefore: true);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->createViews(withSieveBefore: false);
    }

    private function createViews(bool $withSieveBefore): void
    {
        $sqlite = DB::getDriverName() === 'sqlite';

        $address = $sqlite ? "m.local_part || '@' || d.name" : "CONCAT(m.local_part, '@', d.name)";
        $home = $sqlite
            ? "'/home/' || u.username || '/imap/' || d.name || '/' || m.local_part"
            : "CONCAT('/home/', u.username, '/imap/', d.name, '/', m.local_part)";
        $quota = $sqlite
            ? "CASE WHEN m.quota_mb IS NULL THEN NULL ELSE '*:storage=' || m.quota_mb || 'M' END"
            : "CASE WHEN m.quota_mb IS NULL THEN NULL ELSE CONCAT('*:storage=', m.quota_mb, 'M') END";
        $now = $sqlite ? "datetime('now')" : 'UTC_TIMESTAMP()';

        $active = "md.status = 'active' AND u.status = 'active' AND u.role = 'user' AND u.uid IS NOT NULL AND u.gid IS NOT NULL";
        $sieveBefore = $withSieveBefore
            ? ", CASE WHEN EXISTS (SELECT 1 FROM mail_spam_settings ss WHERE ss.mailbox_id = m.id) THEN '/etc/dovecot/sieve/rayxo-none.sieve' ELSE '/etc/dovecot/sieve/rayxo-spam.sieve' END AS sieve_before"
            : '';

        DB::statement('DROP VIEW IF EXISTS v_mail_webmail_sessions');
        DB::statement('DROP VIEW IF EXISTS v_mail_mailboxes');

        DB::statement(<<<SQL
            CREATE VIEW v_mail_mailboxes AS
            SELECT {$address} AS address, m.password_hash AS password, u.uid AS uid, u.gid AS gid,
                {$home} AS home, {$quota} AS quota_rule,
                CASE WHEN m.status = 'active' THEN 1 ELSE 0 END AS can_login{$sieveBefore}
            FROM mailboxes m
            JOIN mail_domains md ON md.id = m.mail_domain_id
            JOIN domains d ON d.id = md.domain_id
            JOIN users u ON u.id = d.user_id
            WHERE m.status IN ('active', 'suspended') AND {$active}
            SQL);

        DB::statement(<<<SQL
            CREATE VIEW v_mail_webmail_sessions AS
            SELECT v.address AS address, s.token_hash AS token_hash
            FROM webmail_sessions s
            JOIN mailboxes m ON m.id = s.mailbox_id
            JOIN mail_domains md ON md.id = m.mail_domain_id
            JOIN domains d ON d.id = md.domain_id
            JOIN v_mail_mailboxes v ON v.address = {$address}
            WHERE s.expires_at > {$now} AND v.can_login = 1
            SQL);
    }
};
