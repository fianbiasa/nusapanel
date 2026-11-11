<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add each login's FTP quota in megabytes to v_ftp_users, which
     * Pure-FTPd reads it from (MYSQLGetQTASZ); null is no quota. MariaDB
     * replaces the view in place, so the read grant of Pure-FTPd's user
     * stays as it is.
     */
    public function up(): void
    {
        $this->createView(withQuota: true);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->createView(withQuota: false);
    }

    private function createView(bool $withQuota): void
    {
        $isSqlite = DB::getDriverName() === 'sqlite';

        $folder = $isSqlite
            ? "'/home/' || u.username || CASE WHEN f.directory = '' THEN '' ELSE '/' || f.directory END"
            : "CONCAT('/home/', u.username, CASE WHEN f.directory = '' THEN '' ELSE CONCAT('/', f.directory) END)";
        $quota = $withQuota ? ', f.quota_mb AS quota_size' : '';

        if ($isSqlite) {
            DB::statement('DROP VIEW IF EXISTS v_ftp_users');
        }

        $create = $isSqlite ? 'CREATE VIEW' : 'CREATE OR REPLACE VIEW';

        DB::statement(<<<SQL
            {$create} v_ftp_users AS
            SELECT f.login AS login, f.password_hash AS password, u.uid AS uid, u.gid AS gid, {$folder} AS dir{$quota}
            FROM ftp_accounts f
            JOIN users u ON u.id = f.user_id
            WHERE f.status = 'active'
                AND u.status = 'active'
                AND u.role = 'user'
                AND u.uid IS NOT NULL
                AND u.gid IS NOT NULL
            SQL);
    }
};
