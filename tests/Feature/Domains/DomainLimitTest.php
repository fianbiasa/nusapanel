<?php

namespace Tests\Feature\Domains;

use App\Agent\AgentClient;
use App\Enums\AccountStatus;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\DomainBandwidth;
use App\Models\Task;
use App\Models\User;
use App\Services\DomainLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\TestCase;

class DomainLimitTest extends TestCase
{
    use RefreshDatabase;

    private User $account;

    private Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->travelTo(now()->setDate(2026, 11, 20)->setTime(12, 0));

        $this->account = User::factory()->create(['username' => 'budi']);
        $this->domain = Domain::factory()->for($this->account, 'account')->create(['name' => 'example.com']);
    }

    public function test_a_website_over_its_bandwidth_limit_stops_with_its_aliases_until_the_limit_is_raised()
    {
        Domain::factory()->pointerOf($this->domain)->create(['name' => 'example.net']);
        $this->usedBandwidth('2026-11-02', 30);
        $this->usedBandwidth('2026-10-31', 500); // Last month does not count.

        $this->actingAs($this->account)
            ->put(route('domains.limits.update', $this->domain), ['bandwidth_limit_mb' => '50', 'disk_limit_mb' => ''])
            ->assertSessionHasNoErrors();

        $this->assertSame(50, $this->domain->fresh()->bandwidth_limit_mb);
        $this->assertNull($this->domain->fresh()->limit_reached);
        $this->assertDatabaseCount('tasks', 0);

        $this->actingAs($this->account)
            ->put(route('domains.limits.update', $this->domain), ['bandwidth_limit_mb' => '20'])
            ->assertSessionHasNoErrors();

        $this->assertSame('bandwidth', $this->domain->fresh()->limit_reached);
        $payloads = Task::query()->orderBy('id')->pluck('payload');
        $this->assertSame(['example.com', 'example.net'], $payloads->pluck('domain')->all());
        $this->assertTrue($payloads[0]['suspended']);
        $this->assertTrue($payloads[1]['suspended']);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'domain.limit_reached', 'subject_id' => $this->domain->id]);

        $this->actingAs($this->account)
            ->get(route('domains.web.show', $this->domain))
            ->assertInertia(fn (Assert $page) => $page
                ->where('limits.bandwidth_limit_mb', 20)
                ->where('limits.bandwidth_used_mb', 30)
                ->where('limits.reached', 'bandwidth'));

        $this->actingAs($this->account)
            ->put(route('domains.limits.update', $this->domain), ['bandwidth_limit_mb' => ''])
            ->assertSessionHasNoErrors();

        $this->assertNull($this->domain->fresh()->limit_reached);
        $latest = Task::query()->orderBy('id')->pluck('payload')->last(fn (array $payload): bool => $payload['domain'] === 'example.com');
        $this->assertArrayNotHasKey('suspended', $latest);
    }

    public function test_the_bandwidth_count_stops_a_website_and_a_new_month_lets_it_run_again()
    {
        $this->domain->forceFill(['bandwidth_limit_mb' => 10])->save();
        $this->usedBandwidth('2026-11-20', 11);
        $limits = app(DomainLimitService::class);

        $this->assertSame(1, $limits->checkBandwidth());
        $this->assertSame('bandwidth', $this->domain->fresh()->limit_reached);
        $this->assertSame(0, $limits->checkBandwidth());

        $this->travelTo(now()->setDate(2026, 12, 1)->setTime(0, 15));

        $this->assertSame(1, $limits->checkBandwidth());
        $this->assertNull($this->domain->fresh()->limit_reached);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'domain.limit_cleared']);
    }

    public function test_the_folder_is_measured_as_the_account_and_a_website_over_its_disk_limit_stops()
    {
        $this->domain->forceFill(['disk_limit_mb' => 100])->save();
        $size = 150 * 1048576;
        $this->mock(AgentClient::class, function (MockInterface $mock) use (&$size): void {
            $mock->shouldReceive('call')
                ->with('fs.disk_usage', ['username' => 'budi', 'paths' => ['domains/example.com']], 600)
                ->andReturnUsing(function () use (&$size): array {
                    return ['usage' => [['path' => 'domains/example.com', 'size' => $size, 'files' => 3, 'folders' => 1, 'truncated' => false]], 'failed' => []];
                });
        });
        $limits = app(DomainLimitService::class);

        $this->assertSame(1, $limits->measureDisk());
        $this->assertSame(150, $this->domain->fresh()->disk_used_mb);
        $this->assertSame('disk', $this->domain->fresh()->limit_reached);
        $this->assertTrue(Task::query()->sole()->payload['suspended']);

        $size = 99 * 1048576;

        $this->assertSame(1, $limits->measureDisk());
        $this->assertNull($this->domain->fresh()->limit_reached);
    }

    public function test_websites_of_accounts_that_are_not_active_are_not_measured()
    {
        $this->domain->forceFill(['disk_limit_mb' => 100])->save();
        $this->account->forceFill(['status' => AccountStatus::Suspended])->save();
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('call'));

        $this->assertSame(0, app(DomainLimitService::class)->measureDisk());
    }

    public function test_limits_must_be_positive_and_pointers_have_none()
    {
        $this->actingAs($this->account)
            ->put(route('domains.limits.update', $this->domain), ['bandwidth_limit_mb' => '0', 'disk_limit_mb' => '-5'])
            ->assertSessionHasErrors(['bandwidth_limit_mb', 'disk_limit_mb']);

        $pointer = Domain::factory()->pointerOf($this->domain)->create();

        $this->actingAs($this->account)
            ->put(route('domains.limits.update', $pointer), ['bandwidth_limit_mb' => '5'])
            ->assertSessionHasErrors('bandwidth_limit_mb');

        $this->assertNull($pointer->fresh()->bandwidth_limit_mb);
    }

    private function usedBandwidth(string $date, int $megabytes): void
    {
        DomainBandwidth::query()->create(['domain_id' => $this->domain->id, 'date' => $date, 'bytes' => $megabytes * 1048576]);
    }
}
