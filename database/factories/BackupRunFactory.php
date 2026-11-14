<?php

namespace Database\Factories;

use App\Enums\BackupRunStatus;
use App\Models\BackupRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BackupRun>
 */
class BackupRunFactory extends Factory
{
    /**
     * Define the model's default state: a scheduled run that backed up two
     * accounts.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'trigger' => BackupRun::TRIGGER_SCHEDULE,
            'status' => BackupRunStatus::Completed,
            'accounts' => 2,
            'succeeded' => 2,
            'failed' => 0,
            'started_at' => now()->subHour(),
            'finished_at' => now()->subMinutes(50),
        ];
    }

    public function running(): static
    {
        return $this->state(['status' => BackupRunStatus::Running, 'succeeded' => 0, 'finished_at' => null]);
    }
}
