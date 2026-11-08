<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Every quantity limit is null when the package does not limit it.
     */
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 64)->unique();
            $table->string('description')->nullable();
            $table->unsignedInteger('disk_mb')->nullable();
            $table->unsignedInteger('bandwidth_mb')->nullable();
            $table->unsignedInteger('domains')->nullable();
            $table->unsignedInteger('subdomains')->nullable();
            $table->unsignedInteger('email_accounts')->nullable();
            $table->unsignedInteger('databases')->nullable();
            $table->unsignedInteger('ftp_accounts')->nullable();
            $table->unsignedInteger('cron_jobs')->nullable();
            $table->boolean('ssl')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('packages');
    }
};
