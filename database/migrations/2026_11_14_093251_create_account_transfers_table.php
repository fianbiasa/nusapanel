<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: hosting accounts moved here from another RAYXO
     * server, one row each. The login key of the other server is kept
     * (encrypted) only while the move goes on.
     */
    public function up(): void
    {
        Schema::create('account_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_url');
            $table->text('api_key')->nullable();
            $table->boolean('verify_tls')->default(true);
            $table->string('username', 32);
            $table->string('status', 16)->index();
            $table->unsignedBigInteger('remote_backup_id')->nullable();
            $table->foreignId('server_backup_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_transfers');
    }
};
