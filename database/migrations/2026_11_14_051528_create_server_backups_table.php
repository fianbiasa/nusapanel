<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: the backups the agent keeps of accounts outside
     * their homes. A backup outlives its account (user_id becomes null),
     * so it keeps the username.
     */
    public function up(): void
    {
        Schema::create('server_backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backup_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('username', 32)->index();
            $table->string('name')->unique();
            $table->string('status', 16);
            $table->boolean('files')->default(false);
            $table->boolean('email')->default(false);
            $table->json('databases');
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('skipped')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('server_backups');
    }
};
