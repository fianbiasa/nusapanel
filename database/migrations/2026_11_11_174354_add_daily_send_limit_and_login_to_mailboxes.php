<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mailboxes get their own daily send limit (recipients per 24 hours,
     * null: the account's), and can be suspended: a suspended mailbox may
     * not sign in (IMAP, POP3, SMTP, webmail), so it sends nothing, but its
     * mail still arrives. v_mail_mailboxes therefore lists suspended
     * mailboxes too, for Postfix and Dovecot's delivery, with can_login,
     * which Dovecot's sign-in and the webmail sessions require.
     */
    public function up(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->unsignedInteger('daily_send_limit')->nullable()->after('quota_mb');
        });

        $this->createViews(withSuspended: true);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->createViews(withSuspended: false);

        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropColumn('daily_send_limit');
        });
    }

    private function createViews(bool $withSuspended): void
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
        $statuses = $withSuspended ? "m.status IN ('active', 'suspended')" : "m.status = 'active'";
        $canLogin = $withSuspended ? ", CASE WHEN m.status = 'active' THEN 1 ELSE 0 END AS can_login" : '';
        $sessionsCanLogin = $withSuspended ? ' AND v.can_login = 1' : '';

        DB::statement('DROP VIEW IF EXISTS v_mail_webmail_sessions');
        DB::statement('DROP VIEW IF EXISTS v_mail_mailboxes');

        DB::statement(<<<SQL
            CREATE VIEW v_mail_mailboxes AS
            SELECT {$address} AS address, m.password_hash AS password, u.uid AS uid, u.gid AS gid,
                {$home} AS home, {$quota} AS quota_rule{$canLogin}
            FROM mailboxes m
            JOIN mail_domains md ON md.id = m.mail_domain_id
            JOIN domains d ON d.id = md.domain_id
            JOIN users u ON u.id = d.user_id
            WHERE {$statuses} AND {$active}
            SQL);

        DB::statement(<<<SQL
            CREATE VIEW v_mail_webmail_sessions AS
            SELECT v.address AS address, s.token_hash AS token_hash
            FROM webmail_sessions s
            JOIN mailboxes m ON m.id = s.mailbox_id
            JOIN mail_domains md ON md.id = m.mail_domain_id
            JOIN domains d ON d.id = md.domain_id
            JOIN v_mail_mailboxes v ON v.address = {$address}
            WHERE s.expires_at > {$now}{$sessionsCanLogin}
            SQL);
    }
};
