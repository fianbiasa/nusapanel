<?php

namespace Database\Factories;

use App\Enums\DomainStatus;
use App\Enums\SslStatus;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Domain>
 */
class DomainFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->unique()->domainWord().'.'.fake()->randomElement(['com', 'net', 'org', 'id', 'co.id']),
            'php_version' => '8.4',
            'is_primary' => false,
            'status' => DomainStatus::Active,
        ];
    }

    /**
     * Indicate that the domain is the account's primary domain.
     */
    public function primary(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_primary' => true,
        ]);
    }

    /**
     * Indicate that the agent has not set the domain up yet.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DomainStatus::Pending,
        ]);
    }

    /**
     * Indicate that the agent failed to set the domain up or remove it.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DomainStatus::Failed,
        ]);
    }

    /**
     * Indicate that the domain is being removed from the server.
     */
    public function deleting(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DomainStatus::Deleting,
        ]);
    }

    /**
     * Indicate that the agent is still issuing the domain's certificate.
     */
    public function sslPending(): static
    {
        return $this->state(fn (array $attributes) => [
            'ssl_status' => SslStatus::Pending,
        ]);
    }

    /**
     * Indicate that the domain is served over HTTPS with its own certificate.
     */
    public function sslActive(bool $forceHttps = false): static
    {
        return $this->state(fn (array $attributes) => [
            'ssl_status' => SslStatus::Active,
            'ssl_names' => fn (array $attributes) => [$attributes['name'], 'www.'.$attributes['name']],
            'ssl_expires_at' => now()->addDays(90)->startOfSecond(),
            'force_https' => $forceHttps,
        ]);
    }

    /**
     * Indicate that the agent could not issue the domain's certificate.
     */
    public function sslFailed(string $error = 'The domain does not point to this server.'): static
    {
        return $this->state(fn (array $attributes) => [
            'ssl_status' => SslStatus::Failed,
            'ssl_error' => $error,
        ]);
    }

    /**
     * Indicate that the domain's certificate is being removed.
     */
    public function sslRemoving(): static
    {
        return $this->state(fn (array $attributes) => [
            'ssl_status' => SslStatus::Removing,
            'ssl_names' => fn (array $attributes) => [$attributes['name'], 'www.'.$attributes['name']],
            'ssl_expires_at' => now()->addDays(90)->startOfSecond(),
        ]);
    }
}
