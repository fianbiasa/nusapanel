<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: whether a website runs CGI scripts, and whether a
     * package (or a reseller's package) allows it. Packages start without
     * CGI, so nothing changes until an administrator allows it. Adding
     * columns never rebuilds the tables, so the views on them stay.
     */
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->boolean('cgi_enabled')->default(false);
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->boolean('cgi')->default(false);
        });

        Schema::table('reseller_packages', function (Blueprint $table) {
            $table->boolean('cgi')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reseller_packages', function (Blueprint $table) {
            $table->dropColumn('cgi');
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('cgi');
        });

        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn('cgi_enabled');
        });
    }
};
