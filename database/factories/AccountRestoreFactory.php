<?php

namespace Database\Factories;

use App\Enums\AccountStatus;
use App\Models\AccountRestore;
use App\Models\ServerBackup;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountRestore>
 */
class AccountRestoreFactory extends Factory
{
    /**
     * Define the model's default state: an account being made again from a
     * backup, waiting for the account to be created.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_backup_id' => ServerBackup::factory()->state(['user_id' => null]),
            'user_id' => User::factory()->state(['status' => AccountStatus::Pending]),
            'actor_id' => User::factory()->admin(),
            'status' => AccountRestore::STATUS_WAITING,
            'skipped' => [],
        ];
    }
}
