<?php

namespace Database\Seeders;

use App\Models\Package;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed demo accounts and packages for local development only.
     *
     * Every account uses the factory password ("password"). On a real server
     * the first admin is created with `php artisan rayxo:create-admin`.
     */
    public function run(): void
    {
        $admin = User::factory()->admin()->create([
            'username' => 'admin',
            'name' => 'Administrator',
            'email' => 'admin@rayxo.test',
        ]);

        $reseller = User::factory()->reseller()->create([
            'username' => 'reseller1',
            'name' => 'Demo Reseller',
            'email' => 'reseller1@rayxo.test',
            'owner_id' => $admin->id,
        ]);

        $basic = Package::factory()->create([
            'name' => 'Basic',
            'description' => 'Untuk website pribadi dan UMKM.',
            'owner_id' => $admin->id,
        ]);

        Package::factory()->unlimited()->create([
            'name' => 'Unlimited',
            'owner_id' => $admin->id,
        ]);

        User::factory()->create([
            'username' => 'budi',
            'name' => 'Budi Santoso',
            'email' => 'budi@rayxo.test',
            'owner_id' => $reseller->id,
            'package_id' => $basic->id,
        ]);
    }
}
