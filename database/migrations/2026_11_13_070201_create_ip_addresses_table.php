<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: the server's extra IP addresses, which hosting
     * accounts can have for their websites. An address belongs to the
     * administrators (no owner) or to one reseller; a dedicated one serves
     * one account only. "added" is whether the agent put it on the network
     * interface, and so takes it off again.
     */
    public function up(): void
    {
        Schema::create('ip_addresses', function (Blueprint $table) {
            $table->id();
            $table->string('address', 45)->unique();
            $table->unsignedTinyInteger('version');
            $table->unsignedTinyInteger('prefix');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('dedicated')->default(false);
            $table->boolean('added')->default(false);
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ip_addresses');
    }
};
