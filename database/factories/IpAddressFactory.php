<?php

namespace Database\Factories;

use App\Models\IpAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IpAddress>
 */
class IpAddressFactory extends Factory
{
    /**
     * Define the model's default state: an IPv4 address of the
     * administrators from TEST-NET-2, which rayxo added.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'address' => '198.51.100.'.fake()->unique()->numberBetween(1, 254),
            'version' => 4,
            'prefix' => 32,
            'owner_id' => null,
            'dedicated' => false,
            'added' => true,
            'note' => null,
        ];
    }

    /**
     * An IPv6 address from the documentation range.
     */
    public function ipv6(): static
    {
        return $this->state(fn (): array => [
            'address' => '2001:db8::'.dechex(fake()->unique()->numberBetween(1, 65535)),
            'version' => 6,
            'prefix' => 128,
        ]);
    }

    /**
     * An address that serves one account at most.
     */
    public function dedicated(): static
    {
        return $this->state(fn (): array => ['dedicated' => true]);
    }
}
