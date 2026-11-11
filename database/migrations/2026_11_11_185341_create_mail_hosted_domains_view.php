<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Every domain hosted here, for Postfix's SRS: mail from these domains
     * keeps its envelope sender (their SPF allows this server), while mail
     * from other domains that this server sends on, such as forwarded mail,
     * goes out as an SRS address of the server's hostname. The same domains
     * as in v_mail_local_senders, whatever their status or email settings,
     * so websites' mail is never rewritten.
     */
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_mail_hosted_domains');

        DB::statement(<<<'SQL'
            CREATE VIEW v_mail_hosted_domains AS
            SELECT d.name AS name
            FROM domains d
            JOIN users u ON u.id = d.user_id
            WHERE u.role = 'user'
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_mail_hosted_domains');
    }
};
