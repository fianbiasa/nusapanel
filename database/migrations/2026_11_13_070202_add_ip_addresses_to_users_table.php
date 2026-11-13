<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: the addresses of its own a hosting account's
     * websites are served on; null is the server's main address of that
     * version.
     */
    public function up(): void
    {
        $this->withoutSqliteViews(function (): void {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('ipv4_address_id')->nullable()->constrained('ip_addresses')->nullOnDelete();
                $table->foreignId('ipv6_address_id')->nullable()->constrained('ip_addresses')->nullOnDelete();
            });
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->withoutSqliteViews(function (): void {
            Schema::table('users', function (Blueprint $table) {
                $table->dropConstrainedForeignId('ipv4_address_id');
                $table->dropConstrainedForeignId('ipv6_address_id');
            });
        });
    }

    /**
     * SQLite adds or drops a foreign key by building the table again, and
     * renaming the new table into place fails while views (the mail and
     * FTP views) select from it, leaving the old table dropped. The views
     * are set aside while the table changes and created again as they
     * were, also when the change fails. MariaDB changes the table in place.
     *
     * No transaction around it: SQLite ignores turning foreign keys off
     * inside one, and dropping the old users table would then cascade to
     * every table that refers to it.
     */
    private function withoutSqliteViews(Closure $change): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $change();

            return;
        }

        $views = DB::select("select name, sql from sqlite_master where type = 'view' order by rowid");

        foreach ($views as $view) {
            DB::statement('drop view "'.$view->name.'"');
        }

        try {
            $change();
        } finally {
            foreach ($views as $view) {
                DB::statement($view->sql);
            }
        }
    }
};
