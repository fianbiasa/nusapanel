<?php

namespace Tests\Feature\Dns;

use App\Enums\AccountStatus;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\Task;
use App\Models\User;
use App\Services\DomainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminDnsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['hosting.public_ip' => '203.0.113.10', 'app.url' => 'https://server.example.net:2222']);
        $this->admin = User::factory()->admin()->create();
    }

    private function zoneOf(User $account, string $name): DnsZone
    {
        $this->app->make(DomainService::class)->create($account, $name, $account);

        return DnsZone::query()->where('name', $name)->sole();
    }

    public function test_the_admin_sees_every_zone_and_can_search()
    {
        $this->zoneOf(User::factory()->create(['username' => 'budi']), 'example.com');
        $this->zoneOf(User::factory()->create(['username' => 'sari']), 'sari.test');

        $this->actingAs($this->admin)
            ->get(route('admin.dns.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('zones.data', 2)
                ->where('zones.data.0.name', 'example.com')
                ->where('zones.data.0.account', 'budi')
                ->where('zones.data.0.records', 6));

        $this->actingAs($this->admin)
            ->get(route('admin.dns.index', ['search' => 'SARI']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('zones.data', 1)
                ->where('zones.data.0.name', 'sari.test'));
    }

    public function test_the_admin_changes_the_records_of_any_zone_even_of_a_suspended_account()
    {
        $account = User::factory()->create(['username' => 'budi']);
        $zone = $this->zoneOf($account, 'example.com');
        $account->forceFill(['status' => AccountStatus::Suspended])->save();

        $this->actingAs($this->admin)
            ->get(route('admin.dns.zones.show', $zone))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/dns/show')
                ->where('zone.account', 'budi')
                ->where('zone.account_status', 'suspended')
                ->has('records', 6));

        $this->actingAs($this->admin)
            ->from(route('admin.dns.zones.show', $zone))
            ->post(route('admin.dns.records.store', $zone), ['type' => 'TXT', 'name' => '_verify', 'content' => 'abc', 'ttl' => 300])
            ->assertRedirect(route('admin.dns.zones.show', $zone))
            ->assertSessionHasNoErrors();

        $record = DnsRecord::query()->where('name', '_verify.example.com')->sole();

        $this->actingAs($this->admin)
            ->put(route('admin.dns.records.update', $record), ['type' => 'TXT', 'name' => '_verify', 'content' => 'def', 'ttl' => 300])
            ->assertSessionHasNoErrors();
        $this->assertSame('def', $record->fresh()->content);

        $this->actingAs($this->admin)->delete(route('admin.dns.records.destroy', $record))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('admin.dns.reset', $zone))->assertSessionHasNoErrors();

        $this->assertModelMissing($record);
        $this->assertSame('budi', Task::query()->where('action', 'dns.sync_zone')->latest('id')->first()->payload['username']);
        $this->assertSame($this->admin->id, Task::query()->where('action', 'dns.sync_zone')->latest('id')->first()->actor_id);
    }

    public function test_zones_of_accounts_being_deleted_cannot_change()
    {
        $account = User::factory()->create();
        $zone = $this->zoneOf($account, 'example.com');
        $account->forceFill(['status' => AccountStatus::Deleting])->save();

        $this->actingAs($this->admin)
            ->post(route('admin.dns.records.store', $zone), ['type' => 'TXT', 'name' => 'x', 'content' => 'abc', 'ttl' => 300])
            ->assertSessionHasErrors(['name' => 'DNS records can only be changed while the account is active.']);
    }

    public function test_only_admins_reach_dns_administration()
    {
        $zone = $this->zoneOf(User::factory()->create(), 'example.com');

        foreach ([User::factory()->create(), User::factory()->reseller()->create()] as $user) {
            $this->actingAs($user)->get(route('admin.dns.index'))->assertForbidden();
            $this->actingAs($user)->get(route('admin.dns.zones.show', $zone))->assertForbidden();
            $this->actingAs($user)->post(route('admin.dns.records.store', $zone), ['type' => 'TXT', 'name' => 'x', 'content' => 'abc', 'ttl' => 300])->assertForbidden();
        }
    }
}
