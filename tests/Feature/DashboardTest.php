<?php

namespace Tests\Feature;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Models\AccountUsage;
use App\Models\Domain;
use App\Models\Package;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\QuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_users_and_resellers_do_not_receive_server_information()
    {
        foreach ([User::factory()->create(), User::factory()->reseller()->create()] as $account) {
            $this->actingAs($account)
                ->get(route('dashboard'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('dashboard')
                    ->missing('systemInfo'));
        }
    }

    public function test_admins_receive_server_information_as_a_deferred_prop()
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->missing('systemInfo')
                ->loadDeferredProps(fn (Assert $reload) => $reload
                    ->where('systemInfo.data.hostname', 'server.nusapanel.test')
                    ->where('systemInfo.error', null)));
    }

    public function test_admins_see_counts_for_the_whole_server_and_recent_activity()
    {
        $admin = User::factory()->admin()->create();
        $active = User::factory()->create();
        User::factory()->suspended()->create();
        Domain::factory()->for($active, 'account')->create();
        Domain::factory()->for($active, 'account')->pending()->create();
        app(AuditLogger::class)->record('account.created', $active, [], $admin);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.accounts.total', 2)
                ->where('stats.accounts.active', 1)
                ->where('stats.accounts.suspended', 1)
                ->where('stats.domains.total', 2)
                ->where('stats.domains.pending', 1)
                ->where('stats.tasks.active', 0)
                ->has('recentActivity', 1)
                ->where('recentActivity.0.action', 'account.created')
                ->where('recentActivity.0.actor', $admin->username));
    }

    public function test_hosting_accounts_see_only_their_own_domains()
    {
        $account = User::factory()->create();
        Domain::factory()->for($account, 'account')->create(['name' => 'mine.example']);
        Domain::factory()->for(User::factory()->create(), 'account')->create(['name' => 'theirs.example']);

        $this->actingAs($account)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.domains.total', 1)
                ->has('domains', 1)
                ->where('domains.0.name', 'mine.example')
                ->missing('recentActivity'));
    }

    public function test_hosting_accounts_see_what_their_package_allows_and_uses()
    {
        $package = Package::factory()->create(['name' => 'Basic', 'domains' => 3, 'email_accounts' => 10, 'ssl' => false]);
        $account = User::factory()->create(['package_id' => $package->id]);
        Domain::factory()->for($account, 'account')->create();

        $this->actingAs($account)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('limits.package', 'Basic')
                ->where('limits.ssl', false)
                ->where('limits.quotas.domains', ['limit' => 3, 'used' => 1])
                ->where('limits.quotas.email_accounts', ['limit' => 10, 'used' => null]));
    }

    public function test_hosting_accounts_see_their_disk_usage_as_last_measured()
    {
        $this->freezeSecond();
        Cache::forever(QuotaService::ENABLED_CACHE_KEY, true);

        $package = Package::factory()->create(['disk_mb' => 1024, 'inodes' => 50000]);
        $account = User::factory()->create(['package_id' => $package->id]);
        AccountUsage::factory()->for($account, 'account')->create([
            'disk_kb' => 120 * 1024 + 1,
            'inodes' => 1234,
            'measured_at' => now()->subMinutes(5),
        ]);

        $this->actingAs($account)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('limits.quotas.disk_mb', ['limit' => 1024, 'used' => 121])
                ->where('limits.quotas.inodes', ['limit' => 50000, 'used' => 1234])
                ->where('limits.measured_at', now()->subMinutes(5)->toIso8601String())
                ->where('limits.quota_enabled', true));
    }

    public function test_disk_usage_is_unknown_until_the_server_measured_it()
    {
        $this->actingAs(User::factory()->create(['package_id' => Package::factory()->create(['disk_mb' => 1024])->id]))
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('limits.quotas.disk_mb', ['limit' => 1024, 'used' => null])
                ->where('limits.quotas.inodes.used', null)
                ->where('limits.measured_at', null)
                ->where('limits.quota_enabled', null));
    }

    public function test_hosting_accounts_learn_when_the_server_does_not_enforce_quotas()
    {
        Cache::forever(QuotaService::ENABLED_CACHE_KEY, false);

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('limits.quota_enabled', false));
    }

    public function test_admins_do_not_receive_package_limits()
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->missing('limits'));
    }

    public function test_admins_see_an_error_when_the_agent_is_unreachable()
    {
        $this->mock(AgentClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('call')
                ->with('system.info')
                ->andThrow(new AgentException('Connection refused'));
        });

        $this->actingAs(User::factory()->admin()->create());

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps(fn (Assert $reload) => $reload
                    ->where('systemInfo.data', null)
                    ->where('systemInfo.error', 'The server agent could not be reached.')));
    }
}
