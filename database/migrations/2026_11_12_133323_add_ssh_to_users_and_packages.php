<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: whether an account turned SSH on, and whether a
     * package (or a reseller's package) allows it. Packages start without
     * SSH, so nothing changes until an administrator allows it. Adding
     * columns never rebuilds the tables, so the views on them stay.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('ssh_enabled')->default(false);
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->boolean('ssh')->default(false);
        });

        Schema::table('reseller_packages', function (Blueprint $table) {
            $table->boolean('ssh')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['reseller_packages' => 'ssh', 'packages' => 'ssh', 'users' => 'ssh_enabled'] as $name => $column) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn($column));
        }
    }
};
