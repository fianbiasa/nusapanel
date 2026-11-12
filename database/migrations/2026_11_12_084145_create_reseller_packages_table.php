<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a reseller may give out to its hosting accounts, in total.
     * Every quantity limit is null when the package does not limit it.
     * Without oversell, the packages of a reseller's accounts together
     * may not give out more than these limits.
     */
    public function up(): void
    {
        Schema::create('reseller_packages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->unique();
            $table->string('description')->nullable();
            $table->unsignedInteger('accounts')->nullable();
            $table->unsignedInteger('disk_mb')->nullable();
            $table->unsignedInteger('inodes')->nullable();
            $table->unsignedInteger('bandwidth_mb')->nullable();
            $table->unsignedInteger('domains')->nullable();
            $table->unsignedInteger('subdomains')->nullable();
            $table->unsignedInteger('pointers')->nullable();
            $table->unsignedInteger('email_accounts')->nullable();
            $table->unsignedInteger('email_forwarders')->nullable();
            $table->unsignedInteger('databases')->nullable();
            $table->unsignedInteger('ftp_accounts')->nullable();
            $table->unsignedInteger('cron_jobs')->nullable();
            $table->boolean('ssl')->default(true);
            $table->boolean('oversell')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reseller_packages');
    }
};
