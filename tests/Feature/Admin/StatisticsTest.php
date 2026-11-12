<?php

namespace Tests\Feature\Admin;

use App\Agent\AgentClient;
use App\Models\AccountUsage;
use App\Models\BandwidthUsage;
use App\Models\Package;
use App\Models\Setting;
use App\Models\UsageSnapshot;
use App\Models\User;
use App\Services\BandwidthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\TestCase;

class StatisticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->travelTo('2026-11-12 10:00:00');
    }

    public function test_admins_see_the_usage_of_every_hosting_account()
    {
        $admin = User::factory()->admin()->create();
        $reseller = User::factory()->reseller()->create();
        $big = User::factory()->for($reseller, 'owner')->create(['username' => 'big', 'package_id' => Package::factory()->create(['bandwidth_mb' => 1])->id]);
        $small = User::factory()->create(['username' => 'small']);
        BandwidthUsage::factory()->for($big, 'account')->create(['date' => '2026-11-10', 'web_bytes' => 3 * 1048576]);
        BandwidthUsage::factory()->for($small, 'account')->create(['date' => '2026-11-12', 'web_bytes' => 1000, 'mail_bytes' => 24]);
        BandwidthUsage::factory()->for($small, 'account')->create(['date' => '2026-10-30', 'web_bytes' => 77]);
        AccountUsage::factory()->for($small, 'account')->create(['disk_kb' => 4096]);
        UsageSnapshot::factory()->for($small, 'account')->create(['date' => '2026-11-11', 'disk_kb' => 2048]);

        $this->actingAs($admin)
            ->get(route('admin.statistics.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/statistics/index')
                ->where('scope', 'admin')
                ->where('totals', ['bandwidth_bytes' => 3 * 1048576 + 1024, 'disk_kb' => 4096, 'accounts' => 2])
                ->has('history', 30)
                ->where('history.29', ['date' => '2026-11-12', 'bandwidth_bytes' => 1024, 'disk_kb' => null])
                ->where('history.28', ['date' => '2026-11-11', 'bandwidth_bytes' => 0, 'disk_kb' => 2048])
                ->where('topBandwidth.0', ['id' => $big->id, 'username' => 'big', 'bytes' => 3 * 1048576, 'limit_mb' => 1])
                ->where('topBandwidth.1.username', 'small')
                ->where('topDisk.0.username', 'small'));
    }

    public function test_a_reseller_sees_only_its_own_accounts()
    {
        $reseller = User::factory()->reseller()->create();
        $own = User::factory()->for($reseller, 'owner')->create(['username' => 'own']);
        $other = User::factory()->create(['username' => 'other']);
        BandwidthUsage::factory()->for($own, 'account')->create(['web_bytes' => 10]);
        BandwidthUsage::factory()->for($other, 'account')->create(['web_bytes' => 99]);
        AccountUsage::factory()->for($other, 'account')->create(['disk_kb' => 99]);

        $this->actingAs($reseller)
            ->get(route('reseller.statistics.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('scope', 'reseller')
                ->where('totals', ['bandwidth_bytes' => 10, 'disk_kb' => 0, 'accounts' => 1])
                ->has('topBandwidth', 1)
                ->where('topBandwidth.0.username', 'own')
                ->has('topDisk', 0));
    }

    public function test_hosting_accounts_see_this_months_bandwidth_and_their_history()
    {
        $account = User::factory()->create(['package_id' => Package::factory()->create(['bandwidth_mb' => 100])->id]);
        BandwidthUsage::factory()->for($account, 'account')->create(['date' => '2026-11-01', 'web_bytes' => 1048576 + 1]);
        BandwidthUsage::factory()->for($account, 'account')->create(['date' => '2026-10-31', 'web_bytes' => 5 * 1048576]);

        $this->actingAs($account)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('limits.quotas.bandwidth_mb', ['limit' => 100, 'used' => 2])
                ->has('usageHistory', 30)
                ->where('usageHistory.17.date', '2026-10-31')
                ->where('usageHistory.17.bandwidth_bytes', 5 * 1048576));
    }

    public function test_admins_turn_suspending_over_the_bandwidth_on_and_off()
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('admin.server-settings.bandwidth'), ['suspend_over_limit' => true])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Setting::query()->where('key', BandwidthService::SUSPEND_SETTING)->value('value'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'server.bandwidth_suspend_changed', 'actor_id' => $admin->id]);

        $this->actingAs($admin)
            ->put(route('admin.server-settings.bandwidth'), ['suspend_over_limit' => 'maybe'])
            ->assertSessionHasErrors('suspend_over_limit');
    }

    public function test_measuring_disk_usage_keeps_todays_snapshot()
    {
        $account = User::factory()->create(['username' => 'budi']);

        $this->mock(AgentClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('call')->with('mail.usage', [], config('agent.timeout'))->andReturn(['mailboxes' => []]);
            $mock->shouldReceive('call')->with('db.report', [], config('agent.timeout'))->andReturn(['databases' => []]);
            $mock->shouldReceive('call')->with('quota.report', [], config('agent.timeout'))->andReturn([
                'enabled' => true,
                'filesystem' => '/',
                'accounts' => [['username' => 'budi', 'uid' => 1001, 'disk_kb' => 300, 'inodes' => 7, 'disk_limit_kb' => 0, 'inode_limit' => 0]],
            ]);
        });

        $this->artisan('rayxo:collect-usage')->assertSuccessful();
        $this->artisan('rayxo:collect-usage')->assertSuccessful();

        $this->assertDatabaseCount('usage_snapshots', 1);
        $this->assertDatabaseHas('usage_snapshots', ['user_id' => $account->id, 'date' => '2026-11-12', 'disk_kb' => 300, 'inodes' => 7]);
    }
}
