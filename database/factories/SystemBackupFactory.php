<?php

namespace Database\Factories;

use App\Enums\ServerBackupStatus;
use App\Models\SystemBackup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SystemBackup>
 */
class SystemBackupFactory extends Factory
{
    /**
     * Define the model's default state: a finished backup of the panel.
     * The file itself is not created.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'rayxo-system-panel.test-'.fake()->unique()->dateTimeThisYear()->format('Y-m-d-His').'.tar.gz',
            'status' => ServerBackupStatus::Completed,
            'size_bytes' => 2 * 1024 * 1024,
            'finished_at' => now()->subHour(),
        ];
    }
}
