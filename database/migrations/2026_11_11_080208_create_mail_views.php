<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * What Postfix and Dovecot read, through a MariaDB user that may only
     * read these views: the domains that take mail, and their mailboxes
     * with their password, the account's Linux uid and gid, the folder the
     * mail is kept in (inside the account's home, so it counts towards its
     * disk quota) and the quota. Mail domains and mailboxes that are not
     * ready, and everything of an account that is not active, are left
     * out, so a suspension takes effect at once.
     */
    public function up(): void
    {
        $sqlite = DB::getDriverName() === 'sqlite';

        $address = $sqlite ? "m.local_part || '@' || d.name" : "CONCAT(m.local_part, '@', d.name)";
        $home = $sqlite
            ? "'/home/' || u.username || '/imap/' || d.name || '/' || m.local_part"
            : "CONCAT('/home/', u.username, '/imap/', d.name, '/', m.local_part)";
        $quota = $sqlite
            ? "CASE WHEN m.quota_mb IS NULL THEN NULL ELSE '*:storage=' || m.quota_mb || 'M' END"
            : "CASE WHEN m.quota_mb IS NULL THEN NULL ELSE CONCAT('*:storage=', m.quota_mb, 'M') END";

        $active = "md.status = 'active' AND u.status = 'active' AND u.role = 'user' AND u.uid IS NOT NULL AND u.gid IS NOT NULL";

        DB::statement('DROP VIEW IF EXISTS v_mail_mailboxes');
        DB::statement('DROP VIEW IF EXISTS v_mail_domains');

        DB::statement(<<<SQL
            CREATE VIEW v_mail_domains AS
            SELECT d.name AS name
            FROM mail_domains md
            JOIN domains d ON d.id = md.domain_id
            JOIN users u ON u.id = d.user_id
            WHERE {$active}
            SQL);

        DB::statement(<<<SQL
            CREATE VIEW v_mail_mailboxes AS
            SELECT {$address} AS address, m.password_hash AS password, u.uid AS uid, u.gid AS gid,
                {$home} AS home, {$quota} AS quota_rule
            FROM mailboxes m
            JOIN mail_domains md ON md.id = m.mail_domain_id
            JOIN domains d ON d.id = md.domain_id
            JOIN users u ON u.id = d.user_id
            WHERE m.status = 'active' AND {$active}
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_mail_mailboxes');
        DB::statement('DROP VIEW IF EXISTS v_mail_domains');
    }
};
