<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Resellers make packages of their own, so a package name only has to
     * be unique among the packages of its owner; the panel also keeps the
     * names of the administrators' packages unique among themselves.
     */
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->unique(['owner_id', 'name']);
            $table->dropUnique(['name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->unique('name');
            $table->dropUnique(['owner_id', 'name']);
        });
    }
};
