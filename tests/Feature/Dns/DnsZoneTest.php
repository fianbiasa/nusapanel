<?php

namespace Tests\Feature\Dns;

use App\Enums\AccountStatus;
use App\Enums\PointerMode;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\Domain;
use App\Models\Task;
use App\Models\User;
use App\Services\AccountService;
use App\Services\DomainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DnsZoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['hosting.public_ip' => '203.0.113.10', 'app.url' => 'https://server.example.net:2222']);
    }

    public function test_a_new_domain_gets_its_zone_from_the_template()
    {
        $account = User::factory()->create(['username' => 'budi']);

        $this->actingAs($account)
            ->post(route('domains.store'), ['domain' => 'example.com'])
            ->assertSessionHasNoErrors();

        $zone = DnsZone::query()->sole();
        $this->assertSame('example.com', $zone->name);
        $this->assertSame($account->id, $zone->user_id);
        $this->assertSame(Domain::query()->sole()->id, $zone->domain_id);

        $this->assertSame([
            'username' => 'budi',
            'zone' => 'example.com',
            'nameservers' => ['ns1.example.net', 'ns2.example.net'],
            'records' => [
                ['name' => 'example.com', 'type' => 'A', 'content' => '203.0.113.10', 'ttl' => 3600, 'priority' => 0],
                ['name' => 'example.com', 'type' => 'MX', 'content' => 'mail.example.com', 'ttl' => 3600, 'priority' => 10],
                ['name' => 'example.com', 'type' => 'TXT', 'content' => 'v=spf1 a mx ip4:203.0.113.10 ~all', 'ttl' => 3600, 'priority' => 0],
                ['name' => 'ftp.example.com', 'type' => 'A', 'content' => '203.0.113.10', 'ttl' => 3600, 'priority' => 0],
                ['name' => 'mail.example.com', 'type' => 'A', 'content' => '203.0.113.10', 'ttl' => 3600, 'priority' => 0],
                ['name' => 'www.example.com', 'type' => 'A', 'content' => '203.0.113.10', 'ttl' => 3600, 'priority' => 0],
            ],
        ], Task::query()->where('action', 'dns.sync_zone')->sole()->payload);
    }

    public function test_subdomains_join_their_domains_zone_and_pointers_get_their_own()
    {
        $account = User::factory()->create();
        $domains = $this->app->make(DomainService::class);
        $domain = $domains->create($account, 'example.com', $account);

        $domains->createSubdomain($domain, 'blog', $account);
        $domains->createPointer($domain, 'example.org', PointerMode::Redirect, $account);

        $this->assertEqualsCanonicalizing(['example.com', 'example.org'], DnsZone::query()->pluck('name')->all());

        $latest = Task::query()->where('action', 'dns.sync_zone')->orderBy('id')->get()->last(fn (Task $task): bool => $task->payload['zone'] === 'example.com');
        $names = collect($latest->payload['records'])->where('type', 'A')->pluck('name')->all();
        $this->assertContains('blog.example.com', $names);
        $this->assertContains('www.blog.example.com', $names);

        $pointerZone = DnsZone::query()->where('name', 'example.org')->sole();
        $this->assertSame(6, $pointerZone->records()->count());
    }

    public function test_removing_a_website_takes_it_out_of_dns()
    {
        $account = User::factory()->create(['username' => 'budi']);
        $domains = $this->app->make(DomainService::class);
        $domain = $domains->create($account, 'example.com', $account);
        $blog = $domains->createSubdomain($domain, 'blog', $account);

        $domains->completeRemoval($blog, $account);

        $this->assertFalse(DnsRecord::query()->whereIn('name', ['blog.example.com', 'www.blog.example.com'])->exists());
        $latest = Task::query()->where('action', 'dns.sync_zone')->latest('id')->first();
        $this->assertNotContains('blog.example.com', collect($latest->payload['records'])->pluck('name')->all());

        $domains->completeRemoval($domain->fresh(), $account);

        $this->assertDatabaseCount('dns_zones', 0);
        $this->assertDatabaseCount('dns_records', 0);
        $this->assertSame(
            ['username' => 'budi', 'zone' => 'example.com'],
            Task::query()->where('action', 'dns.delete_zone')->sole()->payload,
        );
    }

    public function test_a_pending_account_gets_its_zones_once_it_is_created()
    {
        $account = User::factory()->create(['status' => AccountStatus::Pending]);
        $domains = $this->app->make(DomainService::class);

        $domains->create($account, 'example.com', User::factory()->admin()->create());

        $this->assertDatabaseCount('dns_zones', 1);
        $this->assertDatabaseCount('tasks', 0);

        $account->forceFill(['status' => AccountStatus::Active])->save();
        $domains->dispatchPendingDomains($account, null);

        $this->assertSame('example.com', Task::query()->where('action', 'dns.sync_zone')->sole()->payload['zone']);
    }

    public function test_deleting_an_account_removes_its_zones_from_the_server_first()
    {
        $admin = User::factory()->admin()->create();
        $account = User::factory()->create(['username' => 'budi']);
        $domains = $this->app->make(DomainService::class);
        $domains->create($account, 'example.com', $account);
        $domains->create($account, 'example.net', $account);

        $this->app->make(AccountService::class)->delete($account, $admin);

        $actions = Task::query()
            ->whereIn('action', ['dns.delete_zone', 'account.delete'])
            ->orderBy('id')
            ->get()
            ->map(fn (Task $task): string => $task->action.' '.($task->payload['zone'] ?? $task->payload['username']))
            ->all();

        $this->assertSame(['dns.delete_zone example.com', 'dns.delete_zone example.net', 'account.delete budi'], $actions);
    }

    public function test_sync_dns_gives_existing_domains_their_zones_once()
    {
        $active = User::factory()->create();
        Domain::factory()->for($active, 'account')->create(['name' => 'example.com']);
        Domain::factory()->for(User::factory()->suspended()->create(), 'account')->create(['name' => 'example.net']);
        Domain::factory()->for(User::factory()->create(['status' => AccountStatus::Deleting]), 'account')->create(['name' => 'example.org']);

        $this->artisan('rayxo:sync-dns')->expectsOutputToContain('2 DNS zones queued.')->assertSuccessful();
        $this->artisan('rayxo:sync-dns')->assertSuccessful();

        $this->assertEqualsCanonicalizing(['example.com', 'example.net'], DnsZone::query()->pluck('name')->all());
        $this->assertSame(12, DnsRecord::query()->count());
        $this->assertSame(4, Task::query()->where('action', 'dns.sync_zone')->count());
    }
}
