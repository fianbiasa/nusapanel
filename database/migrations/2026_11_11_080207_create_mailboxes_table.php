<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The email accounts of mail domains: <local_part>@<domain>, with a
     * SHA512-CRYPT password Dovecot checks and an optional quota.
     */
    public function up(): void
    {
        Schema::create('mailboxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_domain_id')->constrained()->cascadeOnDelete();
            $table->string('local_part', 64);
            $table->string('password_hash');
            $table->unsignedInteger('quota_mb')->nullable();
            $table->string('status', 16);
            $table->timestamps();

            $table->unique(['mail_domain_id', 'local_part']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mailboxes');
    }
};
