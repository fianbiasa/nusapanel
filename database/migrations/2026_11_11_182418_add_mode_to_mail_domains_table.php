<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A mail domain's mail is taken by this server (local) or by another
     * service, such as Google Workspace or Zoho (external). An external
     * domain drops out of what Postfix reads to take mail (v_mail_domains,
     * v_mail_forwarders, v_mail_catchalls), so mail for it sent from here
     * (websites, cron, other accounts) goes to its MX instead of staying.
     * Its mailboxes can still sign in, so old mail can be moved over IMAP.
     * The provider (google, zoho, other) is the service picked, whose DNS
     * records the panel set and takes back when mail comes back here.
     *
     * Also: a forwarder whose address is a suspended mailbox's still keeps
     * a copy there, as suspended mailboxes still get their mail.
     */
    public function up(): void
    {
        Schema::table('mail_domains', function (Blueprint $table) {
            $table->string('mode', 8)->default('local')->after('status');
            $table->string('provider', 16)->nullable()->after('mode');
        });

        $this->createViews(withMode: true);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->createViews(withMode: false);

        Schema::table('mail_domains', function (Blueprint $table) {
            $table->dropColumn(['mode', 'provider']);
        });
    }

    private function createViews(bool $withMode): void
    {
        $sqlite = DB::getDriverName() === 'sqlite';

        $address = $sqlite ? "f.local_part || '@' || d.name" : "CONCAT(f.local_part, '@', d.name)";
        $withCopy = $sqlite
            ? "m.local_part || '@' || d.name || ',' || f.destinations"
            : "CONCAT(m.local_part, '@', d.name, ',', f.destinations)";
        $catchAll = $sqlite ? "'@' || d.name" : "CONCAT('@', d.name)";

        $active = "md.status = 'active' AND u.status = 'active' AND u.role = 'user' AND u.uid IS NOT NULL AND u.gid IS NOT NULL";
        $copyStatuses = "m.status = 'active'";

        if ($withMode) {
            $active .= " AND md.mode = 'local'";
            $copyStatuses = "m.status IN ('active', 'suspended')";
        }

        DB::statement('DROP VIEW IF EXISTS v_mail_domains');
        DB::statement('DROP VIEW IF EXISTS v_mail_forwarders');
        DB::statement('DROP VIEW IF EXISTS v_mail_catchalls');

        DB::statement(<<<SQL
            CREATE VIEW v_mail_domains AS
            SELECT d.name AS name
            FROM mail_domains md
            JOIN domains d ON d.id = md.domain_id
            JOIN users u ON u.id = d.user_id
            WHERE {$active}
            SQL);

        DB::statement(<<<SQL
            CREATE VIEW v_mail_forwarders AS
            SELECT {$address} AS source,
                CASE WHEN m.id IS NULL THEN f.destinations ELSE {$withCopy} END AS destination
            FROM mail_forwarders f
            JOIN mail_domains md ON md.id = f.mail_domain_id
            JOIN domains d ON d.id = md.domain_id
            JOIN users u ON u.id = d.user_id
            LEFT JOIN mailboxes m ON m.mail_domain_id = f.mail_domain_id AND m.local_part = f.local_part AND {$copyStatuses}
            WHERE {$active}
            SQL);

        DB::statement(<<<SQL
            CREATE VIEW v_mail_catchalls AS
            SELECT {$catchAll} AS source, md.catchall_target AS destination
            FROM mail_domains md
            JOIN domains d ON d.id = md.domain_id
            JOIN users u ON u.id = d.user_id
            WHERE md.catchall_target IS NOT NULL AND {$active}
            SQL);
    }
};
