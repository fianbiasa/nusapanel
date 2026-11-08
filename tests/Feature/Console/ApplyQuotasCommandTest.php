<?php

namespace Tests\Feature\Console;

use App\Enums\AccountStatus;
use App\Models\Package;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ApplyQuotasCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_queues_the_disk_quota_of_every_hosting_account_that_exists_on_the_server()
    {
        Queue::fake();

        $package = Package::factory()->create(['disk_mb' => 1024, 'inodes' => 50000]);
        User::factory()->create(['username' => 'budi', 'package_id' => $package->id]);
        User::factory()->suspended()->create(['username' => 'sari', 'package_id' => Package::factory()->create(['disk_mb' => 512, 'inodes' => null])->id]);
        User::factory()->create(['username' => 'free']);

        foreach ([AccountStatus::Pending, AccountStatus::Deleting, AccountStatus::Failed] as $status) {
            User::factory()->create(['status' => $status, 'package_id' => $package->id]);
        }
        User::factory()->admin()->create();
        User::factory()->reseller()->create();

        $this->artisan('nusapanel:apply-quotas')
            ->expectsOutputToContain('3 accounts queued')
            ->assertSuccessful();

        $payloads = Task::query()
            ->where('action', 'account.set_quota')
            ->get()
            ->mapWithKeys(fn (Task $task): array => [$task->payload['username'] => $task->payload]);

        $this->assertSame([
            'budi' => ['username' => 'budi', 'disk_mb' => 1024, 'inodes' => 50000],
            'free' => ['username' => 'free', 'disk_mb' => 0, 'inodes' => 0],
            'sari' => ['username' => 'sari', 'disk_mb' => 512, 'inodes' => 0],
        ], $payloads->sortKeys()->all());
        $this->assertDatabaseCount('tasks', 3);
    }
}
