<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: a deleted account being made again from a backup.
     * The account is created first; once it is active, its databases,
     * mailboxes and data come back from the backup.
     */
    public function up(): void
    {
        Schema::create('account_restores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_backup_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->index();
            $table->json('skipped')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_restores');
    }
};
