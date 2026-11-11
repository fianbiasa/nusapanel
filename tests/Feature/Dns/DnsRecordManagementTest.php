<?php

namespace Tests\Feature\Dns;

use App\Models\AuditLog;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\Task;
use App\Models\User;
use App\Services\DomainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DnsRecordManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $account;

    private DnsZone $zone;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['hosting.public_ip' => '203.0.113.10', 'app.url' => 'https://server.example.net:2222']);

        $this->account = User::factory()->create(['username' => 'budi']);
        $this->app->make(DomainService::class)->create($this->account, 'example.com', $this->account);
        $this->zone = DnsZone::query()->where('name', 'example.com')->sole();
    }

    public function test_the_page_shows_the_accounts_own_zones_and_records()
    {
        $other = User::factory()->create();
        $this->app->make(DomainService::class)->create($other, 'other.test', $other);

        $this->actingAs($this->account)
            ->get(route('dns.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dns/index')
                ->where('zones', [['id' => $this->zone->id, 'name' => 'example.com']])
                ->where('zone.name', 'example.com')
                ->has('records', 6)
                ->where('records.0.name', '@')
                ->where('records.0.type', 'A')
                ->where('records.1.type', 'MX')
                ->where('records.1.priority', 10)
                ->where('nameservers', ['ns1.example.net', 'ns2.example.net'])
                ->where('max_records', 1000));
    }

    public function test_admins_cannot_reach_dns()
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('dns.index'))
            ->assertForbidden();
    }

    public function test_records_are_stored_in_their_absolute_form_and_sent()
    {
        $this->actingAs($this->account)
            ->from(route('dns.index'))
            ->post(route('dns.records.store', $this->zone), ['type' => 'mx', 'name' => ' Backup ', 'content' => 'Mail2.Example.NET.', 'priority' => 20, 'ttl' => 300])
            ->assertRedirect(route('dns.index'))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'The MX record of backup is being added.');

        $record = DnsRecord::query()->where('type', 'MX')->where('name', 'backup.example.com')->sole();
        $this->assertSame(['mail2.example.net', 20, 300], [$record->content, $record->priority, $record->ttl]);

        $this->actingAs($this->account)->post(route('dns.records.store', $this->zone), ['type' => 'TXT', 'name' => '_dmarc', 'content' => 'v=DMARC1; p=none', 'ttl' => 3600]);
        $this->actingAs($this->account)->post(route('dns.records.store', $this->zone), ['type' => 'A', 'name' => '*.blog', 'content' => '203.0.113.20', 'ttl' => 3600, 'priority' => 5]);
        $this->actingAs($this->account)->post(route('dns.records.store', $this->zone), ['type' => 'CAA', 'name' => '@', 'content' => '0 issue letsencrypt.org', 'ttl' => 3600]);

        $this->assertNull(DnsRecord::query()->where('name', '*.blog.example.com')->sole()->priority);

        $sent = collect(Task::query()->where('action', 'dns.sync_zone')->latest('id')->first()->payload['records']);
        $this->assertTrue($sent->contains(['name' => 'backup.example.com', 'type' => 'MX', 'content' => 'mail2.example.net', 'ttl' => 300, 'priority' => 20]));
        $this->assertTrue($sent->contains(['name' => '_dmarc.example.com', 'type' => 'TXT', 'content' => 'v=DMARC1; p=none', 'ttl' => 3600, 'priority' => 0]));
        $this->assertTrue($sent->contains(['name' => 'example.com', 'type' => 'CAA', 'content' => '0 issue letsencrypt.org', 'ttl' => 3600, 'priority' => 0]));
        $this->assertSame(4, AuditLog::query()->where('action', 'dns.record_created')->where('actor_id', $this->account->id)->count());
    }

    public function test_a_record_can_be_changed_and_deleted()
    {
        $www = DnsRecord::query()->where('name', 'www.example.com')->sole();

        $this->actingAs($this->account)
            ->put(route('dns.records.update', $www), ['type' => 'CNAME', 'name' => 'www', 'content' => 'example.com', 'ttl' => 600])
            ->assertSessionHasNoErrors();

        $www->refresh();
        $this->assertSame(['CNAME', 'example.com', 600], [$www->type, $www->content, $www->ttl]);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'dns.record_updated', 'subject_id' => $this->zone->id]);

        $this->actingAs($this->account)
            ->delete(route('dns.records.destroy', $www))
            ->assertSessionHasNoErrors();

        $this->assertModelMissing($www);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'dns.record_deleted']);
        $sent = collect(Task::query()->where('action', 'dns.sync_zone')->latest('id')->first()->payload['records']);
        $this->assertFalse($sent->contains('name', 'www.example.com'));
    }

    public function test_reset_puts_back_the_default_records()
    {
        $this->app->make(DomainService::class)->createSubdomain($this->zone->domain, 'blog', $this->account);
        DnsRecord::query()->where('name', 'www.example.com')->delete();
        $this->zone->records()->create(['name' => 'example.com', 'type' => 'TXT', 'content' => 'mine', 'ttl' => 300]);

        $this->actingAs($this->account)
            ->post(route('dns.reset', $this->zone))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'example.com is being reset to the default records.');

        $this->assertEqualsCanonicalizing(
            ['example.com A', 'example.com MX', 'example.com TXT', 'ftp.example.com A', 'mail.example.com A', 'www.example.com A', 'blog.example.com A', 'www.blog.example.com A'],
            $this->zone->records()->get()->map(fn (DnsRecord $record): string => $record->name.' '.$record->type)->all(),
        );
        $this->assertFalse($this->zone->records()->where('content', 'mine')->exists());
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'dns.zone_reset']);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidRecords(): array
    {
        return [
            'not ipv4' => [['type' => 'A', 'content' => '999.1.1.1'], 'content'],
            'ipv4 as ipv6' => [['type' => 'AAAA', 'content' => '203.0.113.1'], 'content'],
            'mapped ipv6' => [['type' => 'AAAA', 'content' => '::ffff:203.0.113.1'], 'content'],
            'cname at the domain' => [['type' => 'CNAME', 'name' => '@', 'content' => 'other.example.net'], 'name'],
            'ns at the domain' => [['type' => 'NS', 'name' => '', 'content' => 'ns9.example.net'], 'name'],
            'mx without priority' => [['type' => 'MX', 'content' => 'mail.example.com'], 'priority'],
            'mx priority too high' => [['type' => 'MX', 'content' => 'mail.example.com', 'priority' => 70000], 'priority'],
            'mx to a single label' => [['type' => 'MX', 'content' => 'localhost', 'priority' => 10], 'content'],
            'txt over two lines' => [['type' => 'TXT', 'content' => "a\nb"], 'content'],
            'srv without weight' => [['type' => 'SRV', 'name' => '_sip._tcp', 'content' => '5060 sip.example.com', 'priority' => 10], 'content'],
            'caa with quotes' => [['type' => 'CAA', 'content' => '0 issue "letsencrypt.org"'], 'content'],
            'caa with another tag' => [['type' => 'CAA', 'content' => '0 sign letsencrypt.org'], 'content'],
            'name with a space' => [['type' => 'A', 'name' => 'my site', 'content' => '203.0.113.1'], 'name'],
            'wildcard inside' => [['type' => 'A', 'name' => 'a.*', 'content' => '203.0.113.1'], 'name'],
            'unknown type' => [['type' => 'PTR', 'content' => 'host.example.net'], 'type'],
            'ttl too short' => [['type' => 'A', 'content' => '203.0.113.1', 'ttl' => 10], 'ttl'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('invalidRecords')]
    public function test_invalid_records_are_refused(array $data, string $error)
    {
        $tasks = Task::query()->count();

        $this->actingAs($this->account)
            ->post(route('dns.records.store', $this->zone), [...['name' => 'x', 'ttl' => 3600], ...$data])
            ->assertSessionHasErrors($error);

        $this->assertSame(6, $this->zone->records()->count());
        $this->assertSame($tasks, Task::query()->count());
    }

    public function test_cnames_stand_alone_and_records_are_not_repeated()
    {
        $this->actingAs($this->account)
            ->post(route('dns.records.store', $this->zone), ['type' => 'CNAME', 'name' => 'shop', 'content' => 'shops.example.net', 'ttl' => 3600])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->account)
            ->post(route('dns.records.store', $this->zone), ['type' => 'TXT', 'name' => 'shop', 'content' => 'verify', 'ttl' => 3600])
            ->assertSessionHasErrors(['name' => 'shop is a CNAME, so it can have no other record.']);

        $this->actingAs($this->account)
            ->post(route('dns.records.store', $this->zone), ['type' => 'CNAME', 'name' => 'www', 'content' => 'example.com', 'ttl' => 3600])
            ->assertSessionHasErrors(['name' => 'www already has other records, so it cannot be a CNAME.']);

        $this->actingAs($this->account)
            ->post(route('dns.records.store', $this->zone), ['type' => 'A', 'name' => '@', 'content' => '203.0.113.10', 'ttl' => 300])
            ->assertSessionHasErrors(['content' => 'This record already exists.']);
    }

    public function test_a_zone_holds_at_most_1000_records()
    {
        DB::table('dns_records')->insert(array_map(fn (int $i): array => [
            'dns_zone_id' => $this->zone->id, 'name' => "host{$i}.example.com", 'type' => 'A', 'content' => '203.0.113.1', 'ttl' => 3600,
        ], range(1, 994)));

        $this->actingAs($this->account)
            ->post(route('dns.records.store', $this->zone), ['type' => 'A', 'name' => 'one-more', 'content' => '203.0.113.1', 'ttl' => 3600])
            ->assertSessionHasErrors(['name' => 'A zone may have at most 1000 records.']);
    }

    public function test_other_accounts_zones_and_records_are_missing()
    {
        $intruder = User::factory()->create();
        $record = $this->zone->records()->first();

        $this->actingAs($intruder)
            ->post(route('dns.records.store', $this->zone), ['type' => 'A', 'name' => 'x', 'content' => '203.0.113.1', 'ttl' => 3600])
            ->assertNotFound();
        $this->actingAs($intruder)
            ->put(route('dns.records.update', $record), ['type' => 'A', 'name' => 'x', 'content' => '203.0.113.1', 'ttl' => 3600])
            ->assertNotFound();
        $this->actingAs($intruder)->delete(route('dns.records.destroy', $record))->assertNotFound();
        $this->actingAs($intruder)->post(route('dns.reset', $this->zone))->assertNotFound();

        $this->assertSame(6, $this->zone->records()->count());
        $this->assertModelExists($record);
    }
}
