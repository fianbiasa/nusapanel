<?php

namespace Tests\Feature\Dns;

use App\Models\AuditLog;
use App\Models\DnsRecord;
use App\Models\DnsTemplateRecord;
use App\Models\User;
use App\Services\DomainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ResellerDnsTemplateTest extends TestCase
{
    use RefreshDatabase;

    private const TXT = ['type' => 'TXT', 'name' => '@', 'content' => 'reseller={domain}', 'ttl' => 3600, 'priority' => null];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['hosting.public_ip' => '203.0.113.10', 'app.url' => 'https://server.example.net:2222']);
    }

    public function test_a_reseller_without_its_own_template_shows_and_gets_the_admins()
    {
        $reseller = User::factory()->reseller()->create();
        $adminRecords = DnsTemplateRecord::ofTemplate(null)->count();

        $this->actingAs($reseller)
            ->get(route('reseller.dns-template.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('reseller/dns-template')
                ->where('own', false)
                ->has('template', $adminRecords));

        $this->assertSame($this->adminTemplateNames('example.com'), $this->zoneRecords($this->accountWithDomain($reseller, 'example.com')));
    }

    public function test_a_resellers_own_template_is_what_its_accounts_get()
    {
        $reseller = User::factory()->reseller()->create();

        $this->actingAs($reseller)->post(route('reseller.dns-template.copy'))->assertSessionHasNoErrors();
        $this->actingAs($reseller)->post(route('reseller.dns-template.store'), self::TXT)->assertSessionHasNoErrors();

        $this->assertSame(DnsTemplateRecord::ofTemplate(null)->count() + 1, DnsTemplateRecord::ofTemplate($reseller)->count());
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'dns.template_copied', 'subject_id' => $reseller->id]);

        $records = $this->zoneRecords($this->accountWithDomain($reseller, 'example.com'));
        $this->assertContains('example.com TXT reseller=example.com', $records);

        // Accounts of the admins and of other resellers keep the admins' template.
        $others = [
            $this->accountWithDomain(User::factory()->admin()->create(), 'admin.example'),
            $this->accountWithDomain(User::factory()->reseller()->create(), 'other.example'),
        ];

        foreach ($others as $account) {
            $this->assertStringNotContainsString('TXT reseller=', implode(' ', $this->zoneRecords($account)));
        }
    }

    public function test_a_reseller_changes_and_drops_its_own_template()
    {
        $reseller = User::factory()->reseller()->create();
        $this->actingAs($reseller)->post(route('reseller.dns-template.copy'));
        $record = DnsTemplateRecord::ofTemplate($reseller)->where('type', 'MX')->firstOrFail();

        $this->actingAs($reseller)
            ->put(route('reseller.dns-template.update', $record), ['type' => 'MX', 'name' => '@', 'content' => 'mx.reseller.example', 'ttl' => 600, 'priority' => 5])
            ->assertSessionHasNoErrors();
        $this->assertSame(['mx.reseller.example', 600, 5], [$record->fresh()->content, $record->fresh()->ttl, $record->fresh()->priority]);

        // The same record twice in one template is refused, but the admins'
        // template having it does not matter.
        $this->actingAs($reseller)->post(route('reseller.dns-template.store'), self::TXT);
        $this->actingAs($reseller)->post(route('reseller.dns-template.store'), self::TXT)->assertSessionHasErrors('content');

        $this->actingAs($reseller)->delete(route('reseller.dns-template.destroy', $record))->assertSessionHasNoErrors();
        $this->assertModelMissing($record);

        $this->actingAs($reseller)->post(route('reseller.dns-template.reset'))->assertSessionHasNoErrors();

        $this->assertSame(0, DnsTemplateRecord::ofTemplate($reseller)->count());
        $this->assertSame($this->adminTemplateNames('example.com'), $this->zoneRecords($this->accountWithDomain($reseller, 'example.com')));
    }

    public function test_templates_of_others_are_out_of_reach()
    {
        $reseller = User::factory()->reseller()->create();
        $other = User::factory()->reseller()->create();
        $this->actingAs($other)->post(route('reseller.dns-template.copy'));
        $othersRecord = DnsTemplateRecord::ofTemplate($other)->firstOrFail();
        $adminsRecord = DnsTemplateRecord::ofTemplate(null)->firstOrFail();

        foreach ([$othersRecord, $adminsRecord] as $record) {
            $this->actingAs($reseller)->put(route('reseller.dns-template.update', $record), self::TXT)->assertNotFound();
            $this->actingAs($reseller)->delete(route('reseller.dns-template.destroy', $record))->assertNotFound();
        }

        // The admins' pages only show and change the admins' template.
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->put(route('admin.dns.template.update', $othersRecord), self::TXT)->assertNotFound();
        $this->actingAs($admin)
            ->get(route('admin.dns.index'))
            ->assertInertia(fn (Assert $page) => $page->has('template', DnsTemplateRecord::ofTemplate(null)->count()));

        $this->assertModelExists($othersRecord);
        $this->assertSame($othersRecord->content, $othersRecord->fresh()->content);
    }

    private function accountWithDomain(User $owner, string $domain): User
    {
        $account = User::factory()->for($owner, 'owner')->create();
        $this->app->make(DomainService::class)->create($account, $domain, $account);

        return $account;
    }

    /**
     * @return list<string>
     */
    private function zoneRecords(User $account): array
    {
        return DnsRecord::query()
            ->whereIn('dns_zone_id', $account->dnsZones()->select('id'))
            ->orderBy('name')->orderBy('type')->orderBy('content')
            ->get()
            ->map(fn (DnsRecord $record): string => "{$record->name} {$record->type} {$record->content}")
            ->sort()
            ->values()
            ->all();
    }

    /**
     * What a zone made from the admins' template looks like.
     *
     * @return list<string>
     */
    private function adminTemplateNames(string $zone): array
    {
        $account = User::factory()->for(User::factory()->admin(), 'owner')->create();
        $this->app->make(DomainService::class)->create($account, 'sample.test', $account);
        $records = $this->zoneRecords($account);
        $account->dnsZones()->each(fn ($dnsZone) => $dnsZone->records()->delete());

        $records = array_map(fn (string $record): string => str_replace('sample.test', $zone, $record), $records);
        sort($records);

        return $records;
    }
}
