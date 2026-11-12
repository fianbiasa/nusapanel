<?php

namespace Database\Factories;

use App\Models\ResellerPackage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResellerPackage>
 */
class ResellerPackageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word().' reseller '.fake()->numberBetween(1, 999),
            'description' => null,
            'accounts' => 10,
            'disk_mb' => 10240,
            'inodes' => 500000,
            'bandwidth_mb' => 102400,
            'domains' => 30,
            'subdomains' => 100,
            'pointers' => 50,
            'email_accounts' => 100,
            'email_forwarders' => 100,
            'databases' => 50,
            'ftp_accounts' => 50,
            'cron_jobs' => 50,
            'ssl' => true,
            'oversell' => false,
        ];
    }

    /**
     * Indicate that the reseller may give out more than its limits.
     */
    public function oversold(): static
    {
        return $this->state(fn (array $attributes) => [
            'oversell' => true,
        ]);
    }

    /**
     * Indicate that the package limits nothing.
     */
    public function unlimited(): static
    {
        return $this->state(fn (array $attributes) => array_fill_keys(ResellerPackage::LIMITS, null));
    }
}
