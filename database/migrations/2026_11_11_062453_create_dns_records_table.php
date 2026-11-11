<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The records of each zone. Names are absolute without the trailing dot
     * (example.com, www.example.com); priority is for MX and SRV only.
     */
    public function up(): void
    {
        Schema::create('dns_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dns_zone_id')->constrained()->cascadeOnDelete();
            $table->string('name', 253);
            $table->string('type', 10);
            $table->text('content');
            $table->unsignedInteger('ttl');
            $table->unsignedSmallInteger('priority')->nullable();
            $table->timestamps();

            $table->index(['dns_zone_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dns_records');
    }
};
