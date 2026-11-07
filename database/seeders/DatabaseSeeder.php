<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed demo accounts for local development only.
     *
     * Every account uses the factory password ("password"). On a real server
     * the first admin is created with `php artisan nusapanel:create-admin`.
     */
    public function run(): void
    {
        $admin = User::factory()->admin()->create([
            'username' => 'admin',
            'name' => 'Administrator',
            'email' => 'admin@nusapanel.test',
        ]);

        $reseller = User::factory()->reseller()->create([
            'username' => 'reseller1',
            'name' => 'Demo Reseller',
            'email' => 'reseller1@nusapanel.test',
            'owner_id' => $admin->id,
        ]);

        User::factory()->create([
            'username' => 'budi',
            'name' => 'Budi Santoso',
            'email' => 'budi@nusapanel.test',
            'owner_id' => $reseller->id,
        ]);
    }
}
