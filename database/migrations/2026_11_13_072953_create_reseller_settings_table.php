<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: how a reseller presents the panel to its accounts
     * (name, colour, logo, and the domain they sign in at) and the
     * nameservers its accounts' zones name. Null keeps the panel's own.
     */
    public function up(): void
    {
        Schema::create('reseller_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('brand_name', 40)->nullable();
            $table->string('brand_color', 7)->nullable();
            $table->string('logo_path')->nullable();
            $table->json('nameservers')->nullable();
            $table->string('login_domain', 253)->nullable()->unique();
            $table->string('login_domain_status', 16)->nullable();
            $table->text('login_domain_error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reseller_settings');
    }
};
