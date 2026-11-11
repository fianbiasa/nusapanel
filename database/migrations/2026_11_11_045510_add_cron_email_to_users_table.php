<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where cron mails the output of the account's jobs; null mails
     * nothing, as before.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('cron_email', 254)->nullable()->after('sftp_password_set_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('cron_email');
        });
    }
};
