<?php

namespace Tests\Feature\IpAddresses;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Models\AuditLog;
use App\Models\DnsRecord;
use App\Models\IpAddress;
use App\Models\Task;
use App\Models\User;
use App\Services\DomainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\TestCase;

class IpAddressTest extends TestCase
{
    use RefreshDatabase;

    /**
     * What network.addresses reports: the server's main addresses.
     */
    private const SERVER_ADDRESSES = ['addresses' => [
        ['address' => '203.0.113.10', 'prefix' => 24, 'interface' => 'eth0', 'added' => false, 'primary' => true],
        ['address' => '2001:db8:1::5', 'prefix' => 64, 'interface' => 'eth0', 'added' => false, 'primary' => true],
    ]];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['hosting.public_ip' => '203.0.113.10', 'app.url' => 'https://server.example.net:2222']);
    }

    public function test_admins_add_an_address_the_agent_puts_on_the_server()
    {
        $admin = User::factory()->admin()->create();
        $this->agentAdds('198.51.100.7', 32, added: true);

        $this->actingAs($admin)
            ->post(route('admin.ip-addresses.store'), ['address' => '198.51.100.7', 'prefix' => '', 'note' => 'From the provider'])
            ->assertSessionHasNoErrors();

        $ipAddress = IpAddress::query()->sole();
        $this->assertSame(['198.51.100.7', 4, 32, null, false, true, 'From the provider'], [
            $ipAddress->address, $ipAddress->version, $ipAddress->prefix, $ipAddress->owner_id, $ipAddress->dedicated, $ipAddress->added, $ipAddress->note,
        ]);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'ip.added', 'actor_id' => $admin->id]);
    }

    public function test_an_ipv6_address_is_stored_as_the_agent_writes_it()
    {
        $this->agentAdds('2001:db8:2::10', 128, added: false);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.ip-addresses.store'), ['address' => '2001:DB8:2:0::10'])
            ->assertSessionHasNoErrors();

        $ipAddress = IpAddress::query()->sole();
        $this->assertSame(['2001:db8:2::10', 6, 128, false], [$ipAddress->address, $ipAddress->version, $ipAddress->prefix, $ipAddress->added]);
    }

    public function test_the_servers_main_addresses_cannot_be_added()
    {
        $admin = User::factory()->admin()->create();

        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('call')->with('network.addresses', [], 30)->andReturn(self::SERVER_ADDRESSES)
            ->shouldNotReceive('call')->with('network.add_address', \Mockery::any(), \Mockery::any()));

        foreach (['203.0.113.10', '2001:db8:1::5'] as $address) {
            $this->actingAs($admin)
                ->post(route('admin.ip-addresses.store'), ['address' => $address])
                ->assertSessionHasErrors('address');
        }

        $this->assertDatabaseCount('ip_addresses', 0);
    }

    public function test_addresses_websites_cannot_be_served_on_are_refused()
    {
        $admin = User::factory()->admin()->create();
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('call'));

        foreach (['127.0.0.1', '0.0.0.0', '169.254.1.1', '224.0.0.1', '::1', '::', 'fe80::1', 'ff02::1', '::ffff:198.51.100.7', 'example.com', '198.51.100.7/32'] as $address) {
            $this->actingAs($admin)
                ->post(route('admin.ip-addresses.store'), ['address' => $address])
                ->assertSessionHasErrors('address');
        }

        $this->actingAs($admin)
            ->post(route('admin.ip-addresses.store'), ['address' => '198.51.100.7', 'prefix' => '33'])
            ->assertSessionHasErrors('prefix');

        $this->assertDatabaseCount('ip_addresses', 0);
    }

    public function test_an_address_is_added_once()
    {
        IpAddress::factory()->create(['address' => '198.51.100.7']);
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('call')->with('network.addresses', [], 30)->andReturn(self::SERVER_ADDRESSES)
            ->shouldNotReceive('call')->with('network.add_address', \Mockery::any(), \Mockery::any()));

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.ip-addresses.store'), ['address' => '198.51.100.7'])
            ->assertSessionHasErrors('address');

        $this->assertDatabaseCount('ip_addresses', 1);
    }

    public function test_an_address_the_agent_cannot_add_is_not_stored()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('call')->with('network.addresses', [], 30)->andReturn(self::SERVER_ADDRESSES)
            ->shouldReceive('call')->with('network.add_address', ['address' => '198.51.100.7', 'prefix' => 32], 30)->andThrow(new AgentException('no default route')));

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.ip-addresses.store'), ['address' => '198.51.100.7'])
            ->assertSessionHasErrors('address');

        $this->assertDatabaseCount('ip_addresses', 0);
    }

    public function test_removing_an_address_takes_it_off_the_server_only_when_rayxo_added_it()
    {
        $admin = User::factory()->admin()->create();
        $added = IpAddress::factory()->create(['address' => '198.51.100.7', 'added' => true]);
        $existing = IpAddress::factory()->create(['address' => '198.51.100.8', 'added' => false]);

        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('call')->once()->with('network.remove_address', ['address' => '198.51.100.7'], 30)->andReturn([]));

        $this->actingAs($admin)->delete(route('admin.ip-addresses.destroy', $added))->assertSessionHasNoErrors();
        $this->actingAs($admin)->delete(route('admin.ip-addresses.destroy', $existing))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('ip_addresses', 0);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'ip.removed']);
    }

    public function test_an_address_accounts_have_is_not_removed_or_handed_over()
    {
        $admin = User::factory()->admin()->create();
        $ipAddress = IpAddress::factory()->create();
        User::factory()->for($admin, 'owner')->create(['ipv4_address_id' => $ipAddress->id]);
        $reseller = User::factory()->reseller()->create();
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('call'));

        $this->actingAs($admin)->delete(route('admin.ip-addresses.destroy', $ipAddress))->assertSessionHasErrors('address');
        $this->actingAs($admin)
            ->put(route('admin.ip-addresses.update', $ipAddress), ['owner_id' => $reseller->id, 'dedicated' => '0', 'note' => null])
            ->assertSessionHasErrors('address');

        $this->assertNull($ipAddress->fresh()->owner_id);
        $this->assertModelExists($ipAddress);
    }

    public function test_admins_give_an_address_to_a_reseller_and_mark_it_dedicated()
    {
        $admin = User::factory()->admin()->create();
        $reseller = User::factory()->reseller()->create();
        $ipAddress = IpAddress::factory()->create();

        $this->actingAs($admin)
            ->put(route('admin.ip-addresses.update', $ipAddress), ['owner_id' => $reseller->id, 'dedicated' => '1', 'note' => 'Shop'])
            ->assertSessionHasNoErrors();

        $this->assertSame([$reseller->id, true, 'Shop'], [$ipAddress->fresh()->owner_id, $ipAddress->fresh()->dedicated, $ipAddress->fresh()->note]);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'ip.assigned']);

        $this->actingAs($admin)
            ->put(route('admin.ip-addresses.update', $ipAddress), ['owner_id' => User::factory()->create()->id, 'dedicated' => '1', 'note' => null])
            ->assertSessionHasErrors('owner_id');
    }

    public function test_an_address_several_accounts_have_cannot_be_dedicated()
    {
        $admin = User::factory()->admin()->create();
        $ipAddress = IpAddress::factory()->create();
        User::factory()->count(2)->for($admin, 'owner')->create(['ipv4_address_id' => $ipAddress->id]);

        $this->actingAs($admin)
            ->put(route('admin.ip-addresses.update', $ipAddress), ['owner_id' => null, 'dedicated' => '1', 'note' => null])
            ->assertSessionHasErrors('dedicated');

        $this->assertFalse($ipAddress->fresh()->dedicated);
    }

    public function test_resellers_see_and_change_only_their_own_addresses()
    {
        $reseller = User::factory()->reseller()->create();
        $own = IpAddress::factory()->create(['address' => '198.51.100.7']);
        $own->forceFill(['owner_id' => $reseller->id])->save();
        $foreign = IpAddress::factory()->create(['address' => '198.51.100.8']);
        $account = User::factory()->for($reseller, 'owner')->create(['username' => 'budi', 'ipv4_address_id' => $own->id]);

        $this->actingAs($reseller)
            ->get(route('reseller.ip-addresses.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/ip-addresses/index')
                ->where('scope', 'reseller')
                ->has('addresses', 1)
                ->where('addresses.0.address', '198.51.100.7')
                ->where('addresses.0.accounts', ['budi'])
                ->where('resellers', []));

        $this->actingAs($reseller)
            ->put(route('reseller.ip-addresses.update', $own), ['dedicated' => '1', 'note' => 'Shop'])
            ->assertSessionHasNoErrors();
        $this->assertTrue($own->fresh()->dedicated);

        $this->actingAs($reseller)
            ->put(route('reseller.ip-addresses.update', $own), ['dedicated' => '0', 'note' => null, 'owner_id' => User::factory()->reseller()->create()->id])
            ->assertSessionHasErrors('owner_id');
        $this->assertSame($reseller->id, $own->fresh()->owner_id);

        $this->actingAs($reseller)
            ->put(route('reseller.ip-addresses.update', $foreign), ['dedicated' => '1', 'note' => null])
            ->assertNotFound();
        $this->assertFalse($foreign->fresh()->dedicated);
        $this->assertSame($own->id, $account->fresh()->ipv4_address_id);
    }

    public function test_an_account_gets_its_own_addresses_for_its_websites_and_their_dns()
    {
        $admin = User::factory()->admin()->create();
        $account = User::factory()->for($admin, 'owner')->create(['username' => 'budi']);
        $this->app->make(DomainService::class)->create($account, 'example.com', $account);
        Task::query()->delete();

        $ipv4 = IpAddress::factory()->dedicated()->create(['address' => '198.51.100.7']);
        $ipv6 = IpAddress::factory()->ipv6()->create(['address' => '2001:db8:2::10']);

        $this->actingAs($admin)
            ->put(route('admin.accounts.addresses.update', $account), ['ipv4_address_id' => $ipv4->id, 'ipv6_address_id' => $ipv6->id])
            ->assertSessionHasNoErrors();

        $this->assertSame([$ipv4->id, $ipv6->id], [$account->fresh()->ipv4_address_id, $account->fresh()->ipv6_address_id]);

        $apply = Task::query()->where('action', 'web.apply_domain')->sole()->payload;
        $this->assertSame(['198.51.100.7', '2001:db8:2::10'], [$apply['ipv4'], $apply['ipv6']]);

        $this->assertSame([
            'example.com A 198.51.100.7',
            'example.com AAAA 2001:db8:2::10',
            'ftp.example.com A 203.0.113.10',
            'mail.example.com A 203.0.113.10',
            'www.example.com A 198.51.100.7',
            'www.example.com AAAA 2001:db8:2::10',
        ], $this->addressRecords());
        $this->assertSame(1, Task::query()->where('action', 'dns.sync_zone')->count());
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'account.addresses_changed', 'subject_id' => $account->id]);

        // Back to the server's addresses.
        $this->actingAs($admin)
            ->put(route('admin.accounts.addresses.update', $account), ['ipv4_address_id' => null, 'ipv6_address_id' => null])
            ->assertSessionHasNoErrors();

        $this->assertSame([
            'example.com A 203.0.113.10',
            'ftp.example.com A 203.0.113.10',
            'mail.example.com A 203.0.113.10',
            'www.example.com A 203.0.113.10',
        ], $this->addressRecords());
        $this->assertArrayNotHasKey('ipv4', Task::query()->where('action', 'web.apply_domain')->latest('id')->first()->payload);
    }

    public function test_new_websites_of_an_account_with_its_own_address_point_there()
    {
        $admin = User::factory()->admin()->create();
        $ipv4 = IpAddress::factory()->create(['address' => '198.51.100.7']);
        $account = User::factory()->for($admin, 'owner')->create(['ipv4_address_id' => $ipv4->id]);

        $domain = $this->app->make(DomainService::class)->create($account, 'example.com', $account);
        $this->app->make(DomainService::class)->createSubdomain($domain, 'blog', $account);

        $this->assertSame([
            'blog.example.com A 198.51.100.7',
            'example.com A 198.51.100.7',
            'ftp.example.com A 203.0.113.10',
            'mail.example.com A 203.0.113.10',
            'www.blog.example.com A 198.51.100.7',
            'www.example.com A 198.51.100.7',
        ], $this->addressRecords());
        $this->assertSame(['198.51.100.7'], Task::query()->where('action', 'web.apply_domain')->get()->pluck('payload.ipv4')->unique()->values()->all());
    }

    public function test_an_account_only_gets_addresses_its_owner_may_hand_out()
    {
        $admin = User::factory()->admin()->create();
        $reseller = User::factory()->reseller()->create();
        $account = User::factory()->for($reseller, 'owner')->create();

        $adminsAddress = IpAddress::factory()->create();
        $taken = IpAddress::factory()->dedicated()->create();
        $taken->forceFill(['owner_id' => $reseller->id])->save();
        User::factory()->for($reseller, 'owner')->create(['ipv4_address_id' => $taken->id]);
        $ipv6 = IpAddress::factory()->ipv6()->create();
        $ipv6->forceFill(['owner_id' => $reseller->id])->save();

        foreach ([
            ['ipv4_address_id' => $adminsAddress->id, 'ipv6_address_id' => null],
            ['ipv4_address_id' => $taken->id, 'ipv6_address_id' => null],
            ['ipv4_address_id' => $ipv6->id, 'ipv6_address_id' => null],
        ] as $payload) {
            $this->actingAs($reseller)
                ->put(route('reseller.accounts.addresses.update', $account), $payload)
                ->assertSessionHasErrors('ipv4_address_id');
        }

        $this->actingAs($reseller)
            ->put(route('reseller.accounts.addresses.update', $account), ['ipv4_address_id' => null, 'ipv6_address_id' => $ipv6->id])
            ->assertSessionHasNoErrors();

        $this->assertSame([null, $ipv6->id], [$account->fresh()->ipv4_address_id, $account->fresh()->ipv6_address_id]);

        $this->actingAs($admin)
            ->get(route('admin.accounts.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('ipAddresses', 3)
                ->where('ipAddresses.2.of', (string) $reseller->id)
                ->where('ipAddresses.2.used_by', [$account->id]));
    }

    public function test_an_account_moved_to_another_owner_gives_its_addresses_back()
    {
        $admin = User::factory()->admin()->create();
        $ipAddress = IpAddress::factory()->create();
        $account = User::factory()->for($admin, 'owner')->create(['ipv4_address_id' => $ipAddress->id]);

        $this->actingAs($admin)
            ->put(route('admin.accounts.owner.update', $account), ['owner_id' => User::factory()->reseller()->create()->id, 'package_id' => null])
            ->assertSessionHasNoErrors();

        $this->assertNull($account->fresh()->ipv4_address_id);
    }

    public function test_hosting_accounts_cannot_manage_addresses()
    {
        $user = User::factory()->create();
        $ipAddress = IpAddress::factory()->create();

        $this->actingAs($user)->get(route('admin.ip-addresses.index'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.ip-addresses.store'), ['address' => '198.51.100.9'])->assertForbidden();
        $this->actingAs($user)->put(route('reseller.ip-addresses.update', $ipAddress), ['dedicated' => '1'])->assertForbidden();
        $this->actingAs($user)->put(route('reseller.accounts.addresses.update', $user), ['ipv4_address_id' => $ipAddress->id, 'ipv6_address_id' => null])->assertForbidden();

        $this->assertNull($user->fresh()->ipv4_address_id);
    }

    /**
     * The address records of every zone, as "name type content".
     *
     * @return list<string>
     */
    private function addressRecords(): array
    {
        return DnsRecord::query()
            ->whereIn('type', ['A', 'AAAA'])
            ->orderBy('name')
            ->orderBy('type')
            ->get()
            ->map(fn (DnsRecord $record): string => "{$record->name} {$record->type} {$record->content}")
            ->all();
    }

    private function agentAdds(string $address, int $prefix, bool $added): void
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('call')->with('network.addresses', [], 30)->andReturn(self::SERVER_ADDRESSES)
            ->shouldReceive('call')->once()->with('network.add_address', ['address' => $address, 'prefix' => $prefix], 30)->andReturn(['added' => $added]));
    }
}
