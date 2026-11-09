<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the account's phpMyAdmin session ends: the scheduler then gives
     * its MariaDB owner user a password nobody knows. Null while none is open.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('phpmyadmin_expires_at')->nullable()->after('status')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['phpmyadmin_expires_at']);
            $table->dropColumn('phpmyadmin_expires_at');
        });
    }
};
