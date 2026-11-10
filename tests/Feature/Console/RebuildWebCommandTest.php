<?php

namespace Tests\Feature\Console;

use App\Enums\AccountStatus;
use App\Models\Domain;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RebuildWebCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_queues_every_active_website_with_its_current_state()
    {
        Queue::fake();

        $budi = User::factory()->create(['username' => 'budi']);
        Domain::factory()->for($budi, 'account')->create(['name' => 'plain.example']);
        Domain::factory()->for($budi, 'account')->sslActive(forceHttps: true)->create(['name' => 'secure.example']);
        Domain::factory()->for($budi, 'account')->pending()->create(['name' => 'pending.example']);
        Domain::factory()->for($budi, 'account')->failed()->create(['name' => 'failed.example']);

        $suspended = User::factory()->suspended()->create(['username' => 'sari']);
        Domain::factory()->for($suspended, 'account')->create(['name' => 'sari.example']);

        $leaving = User::factory()->create(['username' => 'leaving', 'status' => AccountStatus::Deleting]);
        Domain::factory()->for($leaving, 'account')->create(['name' => 'leaving.example']);

        $this->artisan('rayxo:rebuild-web')
            ->expectsOutputToContain('3 websites queued')
            ->assertSuccessful();

        $payloads = Task::query()
            ->where('action', 'web.apply_domain')
            ->get()
            ->mapWithKeys(fn (Task $task): array => [$task->payload['domain'] => $task->payload]);

        $this->assertSame(['plain.example', 'sari.example', 'secure.example'], $payloads->keys()->sort()->values()->all());
        $this->assertSame([
            'username' => 'budi',
            'domain' => 'plain.example',
            'php_version' => '8.4',
            'ssl' => false,
            'force_https' => false,
        ], $payloads['plain.example']);
        $this->assertTrue($payloads['secure.example']['ssl']);
        $this->assertTrue($payloads['secure.example']['force_https']);
    }
}
