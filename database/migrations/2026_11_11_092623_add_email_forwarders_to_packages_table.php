<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How many email forwarders a package allows; null is no limit, which
     * existing packages keep.
     */
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->unsignedInteger('email_forwarders')->nullable()->after('email_accounts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('email_forwarders');
        });
    }
};
