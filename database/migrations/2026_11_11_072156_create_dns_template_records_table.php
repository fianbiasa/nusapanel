<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The records a new DNS zone starts with, which the admin may change.
     * Names are relative to the zone ("@" is the zone itself); {domain} and
     * {ip} in the content stand for the zone and the server's address.
     * Starts with the template RAYXO always used.
     */
    public function up(): void
    {
        Schema::create('dns_template_records', function (Blueprint $table) {
            $table->id();
            $table->string('name', 63);
            $table->string('type', 10);
            $table->text('content');
            $table->unsignedInteger('ttl');
            $table->unsignedSmallInteger('priority')->nullable();
            $table->timestamps();
        });

        $now = now();

        DB::table('dns_template_records')->insert(array_map(fn (array $record): array => [
            ...$record,
            'ttl' => 3600,
            'created_at' => $now,
            'updated_at' => $now,
        ], [
            ['name' => '@', 'type' => 'A', 'content' => '{ip}', 'priority' => null],
            ['name' => 'www', 'type' => 'A', 'content' => '{ip}', 'priority' => null],
            ['name' => 'mail', 'type' => 'A', 'content' => '{ip}', 'priority' => null],
            ['name' => 'ftp', 'type' => 'A', 'content' => '{ip}', 'priority' => null],
            ['name' => '@', 'type' => 'MX', 'content' => 'mail.{domain}', 'priority' => 10],
            ['name' => '@', 'type' => 'TXT', 'content' => 'v=spf1 a mx ip4:{ip} ~all', 'priority' => null],
        ]));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dns_template_records');
    }
};
