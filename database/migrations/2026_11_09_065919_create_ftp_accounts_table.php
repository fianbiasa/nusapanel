<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The FTP logins of hosting accounts, <name>@<domain>, each limited to a
     * folder of the account's home ("" is the whole home). Pure-FTPd reads
     * them through the v_ftp_users view.
     */
    public function up(): void
    {
        Schema::create('ftp_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('login', 128)->unique();
            $table->string('password_hash');
            $table->string('directory', 200)->default('');
            $table->string('status', 16);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ftp_accounts');
    }
};
