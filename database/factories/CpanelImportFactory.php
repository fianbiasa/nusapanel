<?php

namespace Database\Factories;

use App\Models\CpanelImport;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<CpanelImport>
 */
class CpanelImportFactory extends Factory
{
    /**
     * Define the model's default state: a cPanel backup queued to become an
     * account here, under its cPanel username.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'file' => 'cpmove-'.fake()->unique()->regexify('[a-z][a-z0-9]{5,10}').'.tar.gz',
            'username' => null,
            'email' => null,
            'password_hash' => Hash::make('Kata-Sandi-Baru-2026'),
            'status' => CpanelImport::STATUS_QUEUED,
        ];
    }
}
