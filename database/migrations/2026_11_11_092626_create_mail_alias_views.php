<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * What Postfix reads to forward mail (virtual_alias_maps), through the
     * same MariaDB user as the other mail views:
     *
     * - v_mail_forwarders: a forwarder's address and where its mail goes,
     *   with the mailbox of the same address first when there is one (it
     *   keeps a copy);
     * - v_mail_catchalls: "@<domain>" and where mail for its other
     *   addresses goes.
     *
     * Postfix looks up an address in full before "@<domain>", and also
     * checks the mailboxes (an address that maps to itself), so a
     * catch-all never takes mail of an address that exists. Everything of
     * a domain or account that is not active is left out, as in the other
     * mail views.
     */
    public function up(): void
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        $address = $sqlite ? "f.local_part || '@' || d.name" : "CONCAT(f.local_part, '@', d.name)";
        $withCopy = $sqlite
            ? "m.local_part || '@' || d.name || ',' || f.destinations"
            : "CONCAT(m.local_part, '@', d.name, ',', f.destinations)";
        $catchAll = $sqlite ? "'@' || d.name" : "CONCAT('@', d.name)";
        $active = "md.status = 'active' AND u.status = 'active' AND u.role = 'user' AND u.uid IS NOT NULL AND u.gid IS NOT NULL";

        DB::statement('DROP VIEW IF EXISTS v_mail_forwarders');
        DB::statement('DROP VIEW IF EXISTS v_mail_catchalls');

        DB::statement(<<<SQL
            CREATE VIEW v_mail_forwarders AS
            SELECT {$address} AS source,
                CASE WHEN m.id IS NULL THEN f.destinations ELSE {$withCopy} END AS destination
            FROM mail_forwarders f
            JOIN mail_domains md ON md.id = f.mail_domain_id
            JOIN domains d ON d.id = md.domain_id
            JOIN users u ON u.id = d.user_id
            LEFT JOIN mailboxes m ON m.mail_domain_id = f.mail_domain_id AND m.local_part = f.local_part AND m.status = 'active'
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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_mail_forwarders');
        DB::statement('DROP VIEW IF EXISTS v_mail_catchalls');
    }
};
