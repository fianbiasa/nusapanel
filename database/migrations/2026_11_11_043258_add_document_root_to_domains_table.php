<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The folder a subdomain is served from, relative to the home; null is
     * its own domains/<name>/public_html.
     */
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->string('document_root', 200)->nullable()->after('php_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn('document_root');
        });
    }
};
