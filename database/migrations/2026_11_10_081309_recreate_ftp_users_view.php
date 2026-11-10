<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Create v_ftp_users again, as create_ftp_users_view does. A server
     * installed before the rename had its tables moved from the database
     * "nusapanel" to "rayxo" (setup.sh), but a view cannot move between
     * databases, so it was dropped there. Elsewhere this replaces the view
     * with the same one.
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
     * Reverse the migrations. The view stays: create_ftp_users_view drops it.
     */
    public function down(): void
    {
        //
    }
};
