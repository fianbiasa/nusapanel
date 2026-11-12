<?php

namespace Database\Factories;

use App\Models\ProtectedDirectory;
use App\Models\ProtectedDirectoryUser;
use App\Support\CryptPassword;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProtectedDirectoryUser>
 */
class ProtectedDirectoryUserFactory extends Factory
{
    /**
     * The hash of the password every factory login has, made once.
     */
    private static ?string $passwordHash = null;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'protected_directory_id' => ProtectedDirectory::factory(),
            'username' => fake()->unique()->userName(),
            'password_hash' => self::$passwordHash ??= CryptPassword::hash('password'),
        ];
    }
}
