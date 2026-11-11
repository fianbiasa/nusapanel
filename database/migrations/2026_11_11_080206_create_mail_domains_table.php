<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The domains that take email on this server, with the DKIM key the
     * server signs their mail with (the private key stays on the server).
     */
    public function up(): void
    {
        Schema::create('mail_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('dkim_selector', 63)->default('rayxo');
            $table->text('dkim_public_key')->nullable();
            $table->string('status', 16);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mail_domains');
    }
};
