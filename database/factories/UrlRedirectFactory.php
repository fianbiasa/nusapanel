<?php

namespace Database\Factories;

use App\Models\Domain;
use App\Models\UrlRedirect;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UrlRedirect>
 */
class UrlRedirectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'domain_id' => Domain::factory(),
            'path' => '/'.fake()->unique()->slug(2),
            'destination' => 'https://'.fake()->domainName().'/'.fake()->slug(2),
            'status_code' => 301,
            'wildcard' => false,
        ];
    }

    /**
     * Indicate that everything under the path is redirected too.
     */
    public function wildcard(): static
    {
        return $this->state(fn (array $attributes) => [
            'wildcard' => true,
        ]);
    }

    /**
     * Indicate that the redirect is temporary.
     */
    public function temporary(): static
    {
        return $this->state(fn (array $attributes) => [
            'status_code' => 302,
        ]);
    }
}
