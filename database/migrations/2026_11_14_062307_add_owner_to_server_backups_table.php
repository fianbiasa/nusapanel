<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: who owned the account (an administrator or a
     * reseller) when the backup was made, so its reseller still finds it
     * once the account is deleted.
     */
    public function up(): void
    {
        Schema::table('server_backups', function (Blueprint $table) {
            $table->foreignId('owner_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('server_backups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_id');
        });
    }
};
