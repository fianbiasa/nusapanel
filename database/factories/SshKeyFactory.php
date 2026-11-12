<?php

namespace Database\Factories;

use App\Models\SshKey;
use App\Models\User;
use App\Support\SshPublicKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SshKey>
 */
class SshKeyFactory extends Factory
{
    /**
     * Define the model's default state: a random ed25519 key.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $key = SshPublicKey::parse(self::ed25519Line(fake()->userName().'@laptop'));

        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'public_key' => $key->line(),
            'fingerprint' => $key->fingerprint(),
        ];
    }

    /**
     * A random ed25519 public key line, as ssh-keygen writes it.
     */
    public static function ed25519Line(string $comment = ''): string
    {
        $blob = pack('N', 11).'ssh-ed25519'.pack('N', 32).random_bytes(32);

        return rtrim('ssh-ed25519 '.base64_encode($blob).' '.$comment);
    }
}
