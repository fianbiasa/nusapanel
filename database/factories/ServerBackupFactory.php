<?php

namespace Database\Factories;

use App\Enums\ServerBackupStatus;
use App\Models\BackupRun;
use App\Models\ServerBackup;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServerBackup>
 */
class ServerBackupFactory extends Factory
{
    /**
     * Define the model's default state: a finished backup of a hosting
     * account's files, mail and databases, from a scheduled run. The file
     * itself is not created.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'backup_run_id' => BackupRun::factory(),
            'user_id' => User::factory(),
            'owner_id' => fn (array $attributes): ?int => User::query()->whereKey($attributes['user_id'])->value('owner_id'),
            'username' => fn (array $attributes): string => (string) User::query()->whereKey($attributes['user_id'])->value('username'),
            'name' => fn (array $attributes): string => $attributes['username'].'-'.fake()->unique()->dateTimeThisYear()->format('Y-m-d-His').'.tar.gz',
            'status' => ServerBackupStatus::Completed,
            'files' => true,
            'email' => true,
            'databases' => [],
            'size_bytes' => 50 * 1024 * 1024,
            'started_at' => now()->subHour(),
            'finished_at' => now()->subMinutes(55),
        ];
    }
}
