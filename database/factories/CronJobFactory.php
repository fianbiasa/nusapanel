<?php

namespace Database\Factories;

use App\Models\CronJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CronJob>
 */
class CronJobFactory extends Factory
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
            'schedule' => fake()->randomElement(['*/5 * * * *', '0 * * * *', '@daily']),
            'command' => 'php artisan schedule:run',
            'description' => fake()->optional()->sentence(3),
            'enabled' => true,
        ];
    }

    /**
     * Indicate that the job is paused.
     */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => ['enabled' => false]);
    }
}
