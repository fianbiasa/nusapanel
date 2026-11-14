<?php

namespace Database\Factories;

use App\Models\AccountTransfer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountTransfer>
 */
class AccountTransferFactory extends Factory
{
    /**
     * Define the model's default state: an account queued to move here
     * from another RAYXO server.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_url' => 'https://old.example.com:2222',
            'api_key' => 'rxk_'.fake()->regexify('[A-Za-z0-9]{40}'),
            'verify_tls' => true,
            'username' => fake()->unique()->regexify('[a-z][a-z0-9]{5,10}'),
            'status' => AccountTransfer::STATUS_QUEUED,
        ];
    }
}
