<?php

namespace Tests\Feature\Domains;

use App\Enums\SslStatus;
use App\Models\Domain;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DomainPackageLimitsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_accounts_below_their_domain_limit_can_add_a_domain()
    {
        $account = $this->accountOnPackage(['domains' => 2]);
        Domain::factory()->for($account, 'account')->create();

        $this->actingAs($account)
            ->post(route('domains.store'), ['domain' => 'example.com'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $account->domains()->count());
    }

    public function test_accounts_at_their_domain_limit_cannot_add_a_domain()
    {
        $account = $this->accountOnPackage(['domains' => 2]);
        Domain::factory()->for($account, 'account')->create();
        Domain::factory()->for($account, 'account')->failed()->create();

        $this->actingAs($account)
            ->post(route('domains.store'), ['domain' => 'example.com'])
            ->assertSessionHasErrors([
                'domain' => 'Your package allows 2 domains. Remove a domain or ask your provider for a bigger package.',
            ]);

        $this->assertSame(2, $account->domains()->count());
        $this->assertDatabaseCount('tasks', 0);
        Queue::assertNothingPushed();
    }

    public function test_domains_that_are_being_removed_do_not_count_towards_the_limit()
    {
        $account = $this->accountOnPackage(['domains' => 1]);
        Domain::factory()->for($account, 'account')->deleting()->create();

        $this->actingAs($account)
            ->post(route('domains.store'), ['domain' => 'example.com'])
            ->assertSessionHasNoErrors();

        $this->assertTrue($account->domains()->where('name', 'example.com')->exists());
    }

    public function test_accounts_without_a_package_or_with_an_unlimited_one_have_no_domain_limit()
    {
        $withoutPackage = User::factory()->create();
        $unlimited = User::factory()->create(['package_id' => Package::factory()->unlimited()->create()->id]);

        foreach ([$withoutPackage, $unlimited] as $account) {
            Domain::factory()->count(5)->for($account, 'account')->create();

            $this->actingAs($account)
                ->post(route('domains.store'), ['domain' => "site-{$account->id}.com"])
                ->assertSessionHasNoErrors();

            $this->assertSame(6, $account->domains()->count());
        }
    }

    public function test_ssl_cannot_be_requested_when_the_package_does_not_include_it()
    {
        $account = $this->accountOnPackage(['ssl' => false]);
        $domain = Domain::factory()->for($account, 'account')->create();

        $this->actingAs($account)
            ->post(route('domains.ssl.store', $domain))
            ->assertSessionHasErrors(['domain' => 'SSL is not included in your package.']);

        $this->assertNull($domain->fresh()->ssl_status);
        $this->assertDatabaseCount('tasks', 0);
        Queue::assertNothingPushed();
    }

    public function test_ssl_can_be_requested_when_the_package_includes_it()
    {
        $account = $this->accountOnPackage(['ssl' => true]);
        $domain = Domain::factory()->for($account, 'account')->create();

        $this->actingAs($account)
            ->post(route('domains.ssl.store', $domain))
            ->assertSessionHasNoErrors();

        $this->assertSame(SslStatus::Pending, $domain->fresh()->ssl_status);
    }

    public function test_the_domains_page_shows_what_the_package_allows_and_uses()
    {
        $account = $this->accountOnPackage(['name' => 'Basic', 'domains' => 3, 'disk_mb' => 1024, 'databases' => null, 'ssl' => false]);
        Domain::factory()->for($account, 'account')->create();
        Domain::factory()->for($account, 'account')->deleting()->create();

        $this->actingAs($account)
            ->get(route('domains.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('domains/index')
                ->where('limits.package', 'Basic')
                ->where('limits.ssl', false)
                ->has('limits.quotas', 11)
                ->where('limits.quotas.domains', ['limit' => 3, 'used' => 1])
                ->where('limits.quotas.disk_mb', ['limit' => 1024, 'used' => null])
                // Databases are counted now that they exist.
                ->where('limits.quotas.databases', ['limit' => null, 'used' => 0]));
    }

    public function test_the_domains_page_shows_no_limits_for_accounts_without_a_package()
    {
        $account = User::factory()->create();
        Domain::factory()->for($account, 'account')->create();

        $this->actingAs($account)
            ->get(route('domains.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('limits.package', null)
                ->where('limits.ssl', true)
                ->where('limits.quotas.domains', ['limit' => null, 'used' => 1]));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function accountOnPackage(array $attributes): User
    {
        return User::factory()->create([
            'package_id' => Package::factory()->create($attributes)->id,
        ]);
    }
}
