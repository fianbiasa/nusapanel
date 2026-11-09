<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Where the domain's certificate comes from (Let's Encrypt or one the
     * customer brought), who issued it, and whether browsers trust it. The
     * certificates issued so far all came from Let's Encrypt.
     */
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->string('ssl_source', 16)->nullable()->after('ssl_status');
            $table->string('ssl_issuer')->nullable()->after('ssl_expires_at');
            $table->boolean('ssl_trusted')->nullable()->after('ssl_issuer');
        });

        DB::table('domains')->whereNotNull('ssl_status')->update(['ssl_source' => 'letsencrypt']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn(['ssl_source', 'ssl_issuer', 'ssl_trusted']);
        });
    }
};
