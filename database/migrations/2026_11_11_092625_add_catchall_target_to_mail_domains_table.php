<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a mail domain's catch-all sends mail for addresses it does not
     * have; null refuses that mail, as before.
     */
    public function up(): void
    {
        Schema::table('mail_domains', function (Blueprint $table) {
            $table->string('catchall_target', 254)->nullable()->after('dkim_public_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mail_domains', function (Blueprint $table) {
            $table->dropColumn('catchall_target');
        });
    }
};
