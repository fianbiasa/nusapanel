<?php

namespace Database\Factories;

use App\Models\Package;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Package>
 */
class PackageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word().' '.fake()->numberBetween(1, 999),
            'description' => null,
            'disk_mb' => 1024,
            'inodes' => 50000,
            'bandwidth_mb' => 10240,
            'domains' => 3,
            'subdomains' => 10,
            'email_accounts' => 10,
            'databases' => 5,
            'ftp_accounts' => 5,
            'cron_jobs' => 5,
            'ssl' => true,
        ];
    }

    /**
     * Indicate that the package limits nothing.
     */
    public function unlimited(): static
    {
        return $this->state(fn (array $attributes) => array_fill_keys(Package::LIMITS, null));
    }

    /**
     * Indicate that the package does not include SSL certificates.
     */
    public function withoutSsl(): static
    {
        return $this->state(fn (array $attributes) => [
            'ssl' => false,
        ]);
    }
}
