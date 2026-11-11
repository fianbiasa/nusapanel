<?php

namespace Tests\Feature\Domains;

use App\Enums\SslSource;
use App\Enums\SslStatus;
use App\Models\Domain;
use App\Models\Task;
use App\Models\User;
use App\Services\DomainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WildcardSslTest extends TestCase
{
    use RefreshDatabase;

    private User $account;

    private Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['hosting.public_ip' => '203.0.113.10', 'app.url' => 'https://server.example.net:2222']);

        $this->account = User::factory()->create(['username' => 'budi']);
        $this->domain = Domain::factory()->for($this->account, 'account')->create(['name' => 'example.com']);
    }

    private function giveWildcard(Domain $domain): void
    {
        $this->app->make(DomainService::class)->completeSslIssue($domain, [
            'names' => [$domain->name, '*.'.$domain->name],
            'expires_at' => '2027-02-05T17:25:43Z',
        ], null);
        $domain->refresh();
    }

    public function test_a_domain_asks_for_a_wildcard_certificate_through_this_servers_dns()
    {
        $this->actingAs($this->account)
            ->post(route('domains.ssl.store', $this->domain), ['wildcard' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertSame([
            'username' => 'budi',
            'domain' => 'example.com',
            'wildcard' => true,
            'nameservers' => ['ns1.example.net', 'ns2.example.net'],
        ], Task::query()->where('action', 'ssl.issue')->sole()->payload);

        $this->giveWildcard($this->domain);

        $this->assertTrue($this->domain->hasWildcardCertificate());

        $this->actingAs($this->account)
            ->get(route('domains.index'))
            ->assertInertia(fn (Assert $page) => $page->where('domains.0.ssl_wildcard', true));
    }

    public function test_only_a_domain_gets_a_wildcard_and_a_plain_request_stays_plain()
    {
        $blog = Domain::factory()->subdomainOf($this->domain, 'blog')->create();

        $this->actingAs($this->account)
            ->post(route('domains.ssl.store', $blog), ['wildcard' => '1'])
            ->assertSessionHasErrors(['domain' => 'Only a domain can get a certificate for all its subdomains.']);

        $this->actingAs($this->account)
            ->post(route('domains.ssl.store', $this->domain))
            ->assertSessionHasNoErrors();

        $this->assertSame(['username' => 'budi', 'domain' => 'example.com'], Task::query()->where('action', 'ssl.issue')->sole()->payload);
    }

    public function test_a_subdomain_uses_its_domains_wildcard_at_once()
    {
        $this->giveWildcard($this->domain);
        $blog = Domain::factory()->subdomainOf($this->domain, 'blog')->create();
        Task::query()->delete();

        $this->actingAs($this->account)
            ->get(route('domains.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('domains.1.name', 'blog.example.com')
                ->where('domains.1.parent_has_wildcard', true));

        $this->actingAs($this->account)
            ->post(route('domains.ssl.store', $blog))
            ->assertSessionHasNoErrors();

        $blog->refresh();
        $this->assertSame(SslStatus::Active, $blog->ssl_status);
        $this->assertSame(SslSource::Parent, $blog->ssl_source);
        $this->assertSame($this->domain->ssl_expires_at->toIso8601String(), $blog->ssl_expires_at->toIso8601String());
        $this->assertFalse(Task::query()->where('action', 'ssl.issue')->exists());

        $payload = Task::query()->where('action', 'web.apply_domain')->sole()->payload;
        $this->assertSame(['blog.example.com', true, 'parent', 'example.com'], [$payload['domain'], $payload['ssl'], $payload['certificate'], $payload['certificate_domain']]);
    }

    public function test_a_subdomain_of_a_domain_without_a_wildcard_gets_its_own_certificate()
    {
        $this->app->make(DomainService::class)->completeSslIssue($this->domain, ['names' => ['example.com', 'www.example.com']], null);
        $blog = Domain::factory()->subdomainOf($this->domain, 'blog')->create();

        $this->actingAs($this->account)
            ->post(route('domains.ssl.store', $blog))
            ->assertSessionHasNoErrors();

        $this->assertSame(SslStatus::Pending, $blog->fresh()->ssl_status);
        $this->assertSame('blog.example.com', Task::query()->where('action', 'ssl.issue')->sole()->payload['domain']);
    }

    public function test_removing_the_wildcard_takes_https_off_the_subdomains_using_it_first()
    {
        $this->giveWildcard($this->domain);
        $blog = Domain::factory()->subdomainOf($this->domain, 'blog')->create();
        $shop = Domain::factory()->subdomainOf($this->domain, 'shop')->create();
        $this->app->make(DomainService::class)->requestSsl($blog, $this->account);
        Task::query()->delete();

        $this->actingAs($this->account)
            ->delete(route('domains.ssl.destroy', $this->domain))
            ->assertSessionHasNoErrors();

        $this->assertSame(SslStatus::Removing, $blog->fresh()->ssl_status);
        $this->assertNull($shop->fresh()->ssl_status);
        $this->assertSame(
            [['blog.example.com', false], ['example.com', false]],
            Task::query()->where('action', 'web.apply_domain')->orderBy('id')->get()
                ->map(fn (Task $task): array => [$task->payload['domain'], $task->payload['ssl']])->all(),
        );
    }
}
