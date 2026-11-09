<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * What Pure-FTPd reads to sign FTP logins in: their SHA512-CRYPT
     * password, the account's Linux uid and gid, and the folder they are
     * shut in. Logins that are not ready, and every login of an account
     * that is not active (suspended, being deleted), are left out, so a
     * suspension takes effect at the next login without touching Pure-FTPd.
     * Pure-FTPd's MariaDB user may only read this view.
     */
    public function up(): void
    {
        $folder = DB::getDriverName() === 'sqlite'
            ? "'/home/' || u.username || CASE WHEN f.directory = '' THEN '' ELSE '/' || f.directory END"
            : "CONCAT('/home/', u.username, CASE WHEN f.directory = '' THEN '' ELSE CONCAT('/', f.directory) END)";

        DB::statement('DROP VIEW IF EXISTS v_ftp_users');
        DB::statement(<<<SQL
            CREATE VIEW v_ftp_users AS
            SELECT f.login AS login, f.password_hash AS password, u.uid AS uid, u.gid AS gid, {$folder} AS dir
            FROM ftp_accounts f
            JOIN users u ON u.id = f.user_id
            WHERE f.status = 'active'
                AND u.status = 'active'
                AND u.role = 'user'
                AND u.uid IS NOT NULL
                AND u.gid IS NOT NULL
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_ftp_users');
    }
};
