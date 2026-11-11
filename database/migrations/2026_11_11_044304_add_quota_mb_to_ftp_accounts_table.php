<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How many megabytes an FTP login may fill its folder with; null is no
     * limit, which every existing login keeps.
     */
    public function up(): void
    {
        Schema::table('ftp_accounts', function (Blueprint $table) {
            $table->unsignedInteger('quota_mb')->nullable()->after('directory');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ftp_accounts', function (Blueprint $table) {
            $table->dropColumn('quota_mb');
        });
    }
};
