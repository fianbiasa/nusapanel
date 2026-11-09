<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The php.ini settings of the website's PHP-FPM pool that differ from
     * the defaults, keyed by their php.ini name, e.g.
     * {"memory_limit": "512M"}. Null while it uses the defaults.
     */
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->json('php_settings')->nullable()->after('php_version');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn('php_settings');
        });
    }
};
