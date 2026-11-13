<?php

namespace Tests\Feature\Console;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Models\ResourceCounter;
use App\Models\ResourceUsage;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Mockery\MockInterface;
use Tests\TestCase;

class CollectResourcesCommandTest extends TestCase
{
    use RefreshDatabase;

    private const MB = 1_048_576;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-14 10:00:00');
    }

    public function test_the_first_reading_starts_the_cpu_count_and_stores_the_memory_of_hosting_accounts()
    {
        $budi = User::factory()->create(['username' => 'budi']);
        User::factory()->admin()->create(['username' => 'root1']);

        $this->agentReports([
            $this->reported('budi', memoryBytes: 100 * self::MB + 1, cpu: [11 => 9_000_000, 12 => 500_000]),
            $this->reported('root1', memoryBytes: self::MB, cpu: [13 => 1]),
            $this->reported('ghost', memoryBytes: self::MB, cpu: [14 => 1]),
            ['username' => 'sari', 'memory_bytes' => -1, 'cpu' => []],
        ]);

        $this->artisan('rayxo:collect-resources')
            ->expectsOutputToContain('CPU and memory read for 1 accounts.')
            ->assertSuccessful();

        $this->assertDatabaseCount('resource_usages', 1);
        $this->assertDatabaseHas('resource_usages', ['user_id' => $budi->id, 'date' => '2026-11-14', 'cpu_ms' => 0, 'memory_peak_mb' => 101]);
        $this->assertEqualsCanonicalizing(
            [[11, 9_000_000], [12, 500_000]],
            ResourceCounter::query()->get()->map(fn (ResourceCounter $counter): array => [$counter->cgroup_id, $counter->cpu_usec])->all(),
        );
    }

    public function test_later_readings_add_what_each_cgroup_used_and_keep_the_days_memory_peak()
    {
        $budi = User::factory()->create(['username' => 'budi']);
        ResourceUsage::factory()->for($budi, 'account')->create(['date' => '2026-11-14', 'cpu_ms' => 1000, 'memory_peak_mb' => 300]);
        ResourceCounter::query()->insert([
            ['user_id' => $budi->id, 'cgroup_id' => 11, 'cpu_usec' => 9_000_000],
            ['user_id' => $budi->id, 'cgroup_id' => 12, 'cpu_usec' => 500_000],
        ]);

        // The slice (11) used 2 seconds more; the SSH session (12) ended
        // and a new one (15) has used 0.25 seconds.
        $this->agentReports([$this->reported('budi', memoryBytes: 50 * self::MB, cpu: [11 => 11_000_000, 15 => 250_000])]);

        $this->artisan('rayxo:collect-resources')->assertSuccessful();

        $today = ResourceUsage::query()->sole();
        $this->assertSame(1000 + 2000 + 250, $today->cpu_ms);
        $this->assertSame(300, $today->memory_peak_mb);
        $this->assertEqualsCanonicalizing([11, 15], ResourceCounter::query()->pluck('cgroup_id')->all());
    }

    public function test_a_new_day_gets_its_own_row()
    {
        $budi = User::factory()->create(['username' => 'budi']);
        ResourceUsage::factory()->for($budi, 'account')->create(['date' => '2026-11-13', 'cpu_ms' => 5000, 'memory_peak_mb' => 300]);
        ResourceCounter::query()->insert(['user_id' => $budi->id, 'cgroup_id' => 11, 'cpu_usec' => 1_000_000]);

        $this->agentReports([$this->reported('budi', memoryBytes: 64 * self::MB, cpu: [11 => 1_600_000])]);

        $this->artisan('rayxo:collect-resources')->assertSuccessful();

        $this->assertDatabaseHas('resource_usages', ['user_id' => $budi->id, 'date' => '2026-11-13', 'cpu_ms' => 5000, 'memory_peak_mb' => 300]);
        $this->assertDatabaseHas('resource_usages', ['user_id' => $budi->id, 'date' => '2026-11-14', 'cpu_ms' => 600, 'memory_peak_mb' => 64]);
    }

    public function test_nothing_is_stored_when_the_agent_cannot_read()
    {
        Exceptions::fake();
        $budi = User::factory()->create(['username' => 'budi']);
        ResourceCounter::query()->insert(['user_id' => $budi->id, 'cgroup_id' => 11, 'cpu_usec' => 1_000_000]);

        $this->mock(AgentClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('call')->once()->andThrow(new AgentException('Connection refused'));
        });

        $this->artisan('rayxo:collect-resources')
            ->expectsOutputToContain('Connection refused')
            ->assertFailed();

        $this->assertDatabaseCount('resource_usages', 0);
        $this->assertSame(1_000_000, ResourceCounter::query()->sole()->cpu_usec);
        Exceptions::assertReported(AgentException::class);
    }

    public function test_it_runs_every_five_minutes_without_overlapping()
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains((string) $event->command, 'rayxo:collect-resources'));

        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    /**
     * @param  list<array<string, mixed>>  $accounts
     */
    private function agentReports(array $accounts): void
    {
        $this->mock(AgentClient::class, function (MockInterface $mock) use ($accounts) {
            $mock->shouldReceive('call')
                ->once()
                ->with('usage.resources', [], config('agent.timeout'))
                ->andReturn(['accounts' => $accounts]);
        });
    }

    /**
     * An account as usage.resources reports it.
     *
     * @param  array<int, int>  $cpu  CPU microseconds by cgroup id.
     * @return array<string, mixed>
     */
    private function reported(string $username, int $memoryBytes, array $cpu): array
    {
        return [
            'username' => $username,
            'memory_bytes' => $memoryBytes,
            'cpu' => array_map(fn (int $id, int $usec): array => ['id' => $id, 'usec' => $usec], array_keys($cpu), $cpu),
        ];
    }
}
