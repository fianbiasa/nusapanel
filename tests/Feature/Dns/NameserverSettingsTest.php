<?php

namespace Tests\Feature\Dns;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use App\Services\DnsService;
use App\Services\DomainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NameserverSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['app.url' => 'https://server.example.net:2222']);
    }

    public function test_nameservers_default_to_the_domain_of_the_hostname()
    {
        $this->assertSame(['ns1.example.net', 'ns2.example.net'], $this->app->make(DnsService::class)->nameservers());

        config(['app.url' => 'https://example.net']);

        $this->assertSame(['ns1.example.net', 'ns2.example.net'], DnsService::defaultNameservers());
    }

    public function test_an_admin_changes_the_nameservers_and_every_zone_follows()
    {
        $admin = User::factory()->admin()->create();
        $account = User::factory()->create();
        $this->app->make(DomainService::class)->create($account, 'example.com', $account);
        Task::query()->delete();

        $this->actingAs($admin)
            ->from(route('admin.server-settings.index'))
            ->put(route('admin.server-settings.nameservers'), ['ns1' => ' NS1.Hosting.TEST ', 'ns2' => 'ns2.hosting.test'])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'The nameservers are saved; 1 DNS zones are being rewritten.');

        $this->assertSame(['ns1.hosting.test', 'ns2.hosting.test'], Setting::query()->where('key', DnsService::NAMESERVERS)->value('value'));
        $this->assertSame(['ns1.hosting.test', 'ns2.hosting.test'], Task::query()->where('action', 'dns.sync_zone')->sole()->payload['nameservers']);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'server.nameservers_changed', 'actor_id' => $admin->id]);

        $this->actingAs($admin)
            ->put(route('admin.server-settings.nameservers'), ['ns1' => 'ns.hosting.test', 'ns2' => ''])
            ->assertSessionHasNoErrors();

        $this->assertSame(['ns.hosting.test'], $this->app->make(DnsService::class)->nameservers());
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function invalidNameservers(): array
    {
        return [
            'missing first' => [['ns1' => '', 'ns2' => 'ns2.hosting.test'], 'ns1'],
            'not a host name' => [['ns1' => 'ns 1.hosting.test'], 'ns1'],
            'single label' => [['ns1' => 'localhost'], 'ns1'],
            'the same twice' => [['ns1' => 'ns1.hosting.test', 'ns2' => 'NS1.hosting.test'], 'ns2'],
        ];
    }

    /**
     * @param  array<string, string>  $data
     */
    #[DataProvider('invalidNameservers')]
    public function test_invalid_nameservers_are_refused(array $data, string $error)
    {
        $this->actingAs(User::factory()->admin()->create())
            ->put(route('admin.server-settings.nameservers'), $data)
            ->assertSessionHasErrors($error);

        $this->assertDatabaseMissing('settings', ['key' => DnsService::NAMESERVERS]);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_only_admins_change_the_nameservers()
    {
        $this->actingAs(User::factory()->create())
            ->put(route('admin.server-settings.nameservers'), ['ns1' => 'ns1.hosting.test'])
            ->assertForbidden();

        $this->assertDatabaseMissing('settings', ['key' => DnsService::NAMESERVERS]);
    }
}
