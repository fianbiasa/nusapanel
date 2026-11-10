<?php

namespace Tests\Feature\Domains;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Enums\DomainStatus;
use App\Enums\PointerMode;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Task;
use App\Models\User;
use App\Services\PhpVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

class PhpVersionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Cache::forget(PhpVersionService::CACHE_KEY);
    }

    public function test_the_domains_page_offers_the_installed_php_versions()
    {
        $this->agentReports(['8.4', '7.4', '8.3', 'x', '8.4']);

        $this->actingAs(User::factory()->create())
            ->get(route('domains.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('php_versions', ['7.4', '8.3', '8.4'])
                ->where('default_php_version', '8.4'));
    }

    public function test_only_the_default_version_is_offered_while_the_agent_cannot_be_asked()
    {
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->with('php.versions')->andThrow(new AgentException('agent is down'));
        $this->app->instance(AgentClient::class, $agent);

        $this->assertSame(['8.4'], $this->app->make(PhpVersionService::class)->available());

        // Nothing is cached, so the next call asks again.
        $this->assertFalse(Cache::has(PhpVersionService::CACHE_KEY));
    }

    public function test_the_versions_are_cached_until_the_websites_are_rebuilt()
    {
        $agent = $this->agentReports(['8.3', '8.4']);
        $versions = $this->app->make(PhpVersionService::class);

        $versions->available();
        $versions->available();
        $agent->shouldHaveReceived('call')->with('php.versions')->once();

        $this->artisan('rayxo:rebuild-web')->assertSuccessful();

        $this->assertFalse(Cache::has(PhpVersionService::CACHE_KEY));
    }

    public function test_accounts_can_switch_a_website_and_its_pointers_to_another_php_version()
    {
        $this->agentReports(['8.2', '8.4']);
        $account = User::factory()->create(['username' => 'budi']);
        $domain = Domain::factory()->for($account, 'account')->create(['name' => 'example.com']);
        $alias = Domain::factory()->pointerOf($domain)->create(['name' => 'example.net']);
        $forward = Domain::factory()->pointerOf($domain, PointerMode::Redirect)->create(['name' => 'example.org']);
        $subdomain = Domain::factory()->subdomainOf($domain, 'blog')->create();

        $this->actingAs($account)
            ->from(route('domains.index'))
            ->put(route('domains.php.update', $domain), ['php_version' => '8.2'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('domains.index'));

        foreach ([$domain, $alias, $forward] as $website) {
            $website->refresh();
            $this->assertSame('8.2', $website->php_version);
            $this->assertSame(DomainStatus::Pending, $website->status);
        }

        // A subdomain has a PHP version of its own.
        $this->assertSame('8.4', $subdomain->fresh()->php_version);

        $payloads = Task::query()->where('action', 'web.apply_domain')->orderBy('id')->pluck('payload');

        $this->assertSame(['example.com', 'example.net', 'example.org'], $payloads->pluck('domain')->all());
        $this->assertSame(['8.2', '8.2', '8.2'], $payloads->pluck('php_version')->all());

        $this->assertDatabaseHas(AuditLog::class, [
            'action' => 'domain.php_changed',
            'actor_id' => $account->id,
            'subject_id' => $domain->id,
        ]);
    }

    public function test_a_subdomain_can_switch_on_its_own()
    {
        $this->agentReports(['8.3', '8.4']);
        $account = User::factory()->create();
        $domain = Domain::factory()->for($account, 'account')->create(['name' => 'example.com']);
        $subdomain = Domain::factory()->subdomainOf($domain, 'blog')->create();

        $this->actingAs($account)
            ->put(route('domains.php.update', $subdomain), ['php_version' => '8.3'])
            ->assertSessionHasNoErrors();

        $this->assertSame('8.3', $subdomain->fresh()->php_version);
        $this->assertSame('8.4', $domain->fresh()->php_version);
        $this->assertSame('blog.example.com', Task::query()->sole()->payload['domain']);
    }

    public function test_only_installed_versions_can_be_chosen()
    {
        $this->agentReports(['8.3', '8.4']);
        $account = User::factory()->create();
        $domain = Domain::factory()->for($account, 'account')->create();

        foreach (['5.6', '8.9', '../8.4', ''] as $version) {
            $this->actingAs($account)
                ->put(route('domains.php.update', $domain), ['php_version' => $version])
                ->assertSessionHasErrors('php_version');
        }

        $this->assertSame('8.4', $domain->fresh()->php_version);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_pointers_and_busy_websites_keep_their_version()
    {
        $this->agentReports(['8.3', '8.4']);
        $account = User::factory()->create();
        $domain = Domain::factory()->for($account, 'account')->create();

        $websites = [
            Domain::factory()->pointerOf($domain)->create(),
            Domain::factory()->for($account, 'account')->pending()->create(),
            Domain::factory()->for($account, 'account')->deleting()->create(),
            Domain::factory()->for($account, 'account')->sslPending()->create(),
        ];

        foreach ($websites as $website) {
            $this->actingAs($account)
                ->put(route('domains.php.update', $website), ['php_version' => '8.3'])
                ->assertSessionHasErrors('php_version');

            $this->assertSame('8.4', $website->fresh()->php_version);
        }

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_choosing_the_current_version_changes_nothing()
    {
        $this->agentReports(['8.4']);
        $account = User::factory()->create();
        $domain = Domain::factory()->for($account, 'account')->create();

        $this->actingAs($account)
            ->put(route('domains.php.update', $domain), ['php_version' => '8.4'])
            ->assertSessionHasNoErrors();

        $this->assertSame(DomainStatus::Active, $domain->fresh()->status);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_accounts_cannot_switch_another_accounts_website()
    {
        $this->agentReports(['8.3', '8.4']);
        $domain = Domain::factory()->create();

        $this->actingAs(User::factory()->create())
            ->put(route('domains.php.update', $domain), ['php_version' => '8.3'])
            ->assertNotFound();

        $this->assertSame('8.4', $domain->fresh()->php_version);
    }

    /**
     * Make the agent report the given installed PHP versions.
     *
     * @param  list<string>  $versions
     */
    private function agentReports(array $versions): AgentClient&Mockery\MockInterface
    {
        $agent = Mockery::spy(AgentClient::class);
        $agent->shouldReceive('call')->with('php.versions')->andReturn(['versions' => $versions]);
        $this->app->instance(AgentClient::class, $agent);

        return $agent;
    }
}
