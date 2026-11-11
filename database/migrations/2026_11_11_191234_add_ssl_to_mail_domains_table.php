<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where the certificate of mail.<domain> stands (pending, active,
     * failed; null until it is first asked for), which mail apps get when
     * they connect to mail.<domain>, and why the last try failed.
     */
    public function up(): void
    {
        Schema::table('mail_domains', function (Blueprint $table) {
            $table->string('ssl_status', 16)->nullable()->after('provider');
            $table->string('ssl_error', 500)->nullable()->after('ssl_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mail_domains', function (Blueprint $table) {
            $table->dropColumn(['ssl_status', 'ssl_error']);
        });
    }
};
