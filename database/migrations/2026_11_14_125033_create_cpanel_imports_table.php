<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: hosting accounts imported from a cPanel backup in
     * the backups folder, one row each, with what was left out.
     */
    public function up(): void
    {
        Schema::create('cpanel_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('file');
            $table->string('username', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('password_hash')->nullable();
            $table->foreignId('package_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 16)->index();
            $table->string('cpanel_user', 64)->nullable();
            $table->foreignId('server_backup_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->json('notes')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cpanel_imports');
    }
};
