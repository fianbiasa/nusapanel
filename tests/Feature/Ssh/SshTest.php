<?php

namespace Tests\Feature\Ssh;

use App\Models\AuditLog;
use App\Models\Package;
use App\Models\ResellerPackage;
use App\Models\SshKey;
use App\Models\Task;
use App\Models\User;
use App\Services\AccountService;
use App\Services\SshService;
use Database\Factories\SshKeyFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SshTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Public keys made with ssh-keygen, and the fingerprints ssh-keygen -l
     * shows for them.
     */
    private const ED25519 = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIP9Wc6pG43wKPikyvKxtSgMlBQf218c6QMV5E0pLDpIP budi@laptop';

    private const ED25519_FINGERPRINT = 'SHA256:hHDw+stLwZSCmtEHLB9x9KD2MiF1wWm/eECG6zxNEb8';

    private const ECDSA = 'ecdsa-sha2-nistp256 AAAAE2VjZHNhLXNoYTItbmlzdHAyNTYAAAAIbmlzdHAyNTYAAABBBGH9edIB8RUoUUsqRtvd8xrXHtfgh8wjvhLaoASuuDlklA4xLQ5jbJGj2Hd4bIRAVmKK6gdyNHZXiyIsZ3bngNg=';

    private const ECDSA_FINGERPRINT = 'SHA256:ICiiq9mJDKzpGFD8XgUyTqU0/uQZF4PPP7W935h5lTI';

    private const RSA_3072 = 'ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABgQCwucavvuDvgUuwCNnuGVr/zqTS4FYJnwkHsNbMJYlC5hKwxxpTf6xc/8/ZYgwA71n7rP5StZguXf59ryOdSyIGiOY2G6M5FvqUPfFs27s5EFPm2j0HMTz+CUf5Zly1qomEI8AOtEEljM9uitxapiUxP/NzxKXDUjTd+HcamXUnUI04Wbjd/c9fXI4no5HI9JIw9fjDPFWDnr+NVNmW7fXo+8kjyrC5lFae7euw0gY5/xjcPyGSm/hYaXioLl2lEaIn9ysWP/EQyhX3fqLcnU6XQ0e0taBli76AGStbQ6fKON/07prDuyG7TkY+1O/KYq1CeTpB0aEWo1/3/O4ErsfwdXzEgcdGYJ9lAAQ5ceLzAhuS9fIfhPZm9adeVulVY/ja1wC+1jZJohRA/DkW1aoy3mbQyDxUXOXKBDpmk83pbUgswDfdE4gnCwPMl54JDGxJAE+zI4QrXlNKZTUkkMvNnRvmygl8KvHt2y69SYbNk/qnP2oH0q7ssuitSfBft/8= server';

    private const RSA_3072_FINGERPRINT = 'SHA256:i0H1P9m/XRp4U4jSVQxKkRbw0r4+pBJbUkADRPGJrRo';

    private const RSA_1024 = 'ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAAAgQDK6WQv6ju+Pn8Fk1SbWR1YAUGNOCr4Abd64/vfYN9RD1Z7ARgS9qGqijqcd6gcJjrxBYYcIJUyOphcldxv/nFvSC3gsd8XKLPA/5p/SVfinqXvyZuMsGFdb8u0VdD2H7bP6htCnBHGcZAJ2WvVSrQyqVbPBHh34vRn3EIcT/CQXw== old';

    private User $admin;

    private Package $package;

    private User $account;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['app.url' => 'https://server.example.net:2222']);

        $this->admin = User::factory()->admin()->create();
        $this->package = Package::factory()->for($this->admin, 'owner')->create(['ssh' => true]);
        $this->account = User::factory()->create(['username' => 'budi', 'owner_id' => $this->admin->id, 'package_id' => $this->package->id]);
    }

    public function test_the_page_shows_how_to_connect_and_the_keys()
    {
        SshKey::factory()->for($this->account, 'account')->create(['name' => 'Laptop', 'fingerprint' => self::ED25519_FINGERPRINT]);
        SshKey::factory()->create();

        $this->actingAs($this->account)
            ->get(route('ssh.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('ssh/index')
                ->where('access.allowed', true)
                ->where('access.enabled', false)
                ->where('access.active', false)
                ->where('connection.host', 'server.example.net')
                ->where('connection.username', 'budi')
                ->has('keys', 1)
                ->where('keys.0.name', 'Laptop')
                ->where('keys.0.type', 'ssh-ed25519')
                ->where('keys.0.fingerprint', self::ED25519_FINGERPRINT)
                ->where('max_keys', 20));
    }

    public function test_ssh_is_turned_on_and_off_for_the_account()
    {
        $this->actingAs($this->account)
            ->put(route('ssh.access.update'), ['enabled' => true])
            ->assertSessionHasNoErrors();

        $this->assertTrue($this->account->fresh()->ssh_enabled);
        $this->assertSame(['username' => 'budi', 'enabled' => true], Task::query()->sole()->payload);
        $this->assertSame('account.set_ssh', Task::query()->sole()->action);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'account.ssh_enabled']);

        $this->actingAs($this->account)
            ->put(route('ssh.access.update'), ['enabled' => false])
            ->assertSessionHasNoErrors();

        $this->assertFalse($this->account->fresh()->ssh_enabled);
        $this->assertFalse(Task::query()->latest('id')->first()->payload['enabled']);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'account.ssh_disabled']);
    }

    public function test_ssh_needs_a_package_and_a_reseller_package_that_include_it()
    {
        $this->package->update(['ssh' => false]);

        $this->actingAs($this->account)
            ->put(route('ssh.access.update'), ['enabled' => true])
            ->assertSessionHasErrors(['enabled' => 'SSH is not included in your package.']);

        // A reseller whose own package has no SSH cannot give it either.
        $reseller = User::factory()->reseller()->create(['reseller_package_id' => ResellerPackage::factory()->create(['ssh' => false])->id]);
        $customer = User::factory()->create([
            'owner_id' => $reseller->id,
            'package_id' => Package::factory()->for($reseller, 'owner')->create(['ssh' => true])->id,
        ]);

        $this->actingAs($customer)
            ->put(route('ssh.access.update'), ['enabled' => true])
            ->assertSessionHasErrors('enabled');

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_a_suspended_account_cannot_change_ssh()
    {
        // Signing in is refused already; the service refuses it too, for
        // an administrator acting for the account.
        $suspended = User::factory()->suspended()->create(['owner_id' => $this->admin->id, 'package_id' => $this->package->id]);

        $this->actingAs($suspended)
            ->put(route('ssh.access.update'), ['enabled' => true])
            ->assertSessionHasErrors();

        $this->expectExceptionMessage('SSH can only be changed while the account is active.');
        app(SshService::class)->setEnabled($suspended, true, $this->admin);

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_the_shell_stops_when_the_package_drops_ssh_and_comes_back_with_it()
    {
        $this->account->forceFill(['ssh_enabled' => true])->save();
        User::factory()->create(['owner_id' => $this->admin->id, 'package_id' => $this->package->id]);

        $this->actingAs($this->admin)
            ->put(route('admin.packages.update', $this->package), [...$this->packageFields(), 'ssh' => '0'])
            ->assertSessionHasNoErrors();

        // Only the account that turned SSH on gets a task; it keeps its
        // choice for when the package allows SSH again.
        $this->assertSame(['username' => 'budi', 'enabled' => false], Task::query()->sole()->payload);
        $this->assertTrue($this->account->fresh()->ssh_enabled);

        $this->actingAs($this->admin)
            ->put(route('admin.packages.update', $this->package), [...$this->packageFields(), 'ssh' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Task::query()->latest('id')->first()->payload['enabled']);
    }

    public function test_the_shell_follows_the_account_to_another_package_or_owner()
    {
        $this->account->forceFill(['ssh_enabled' => true])->save();
        $accounts = app(AccountService::class);

        $accounts->changePackage($this->account, Package::factory()->for($this->admin, 'owner')->create(['ssh' => false]), $this->admin);
        $this->assertFalse($this->lastSshTask()['enabled']);

        $accounts->changePackage($this->account, $this->package, $this->admin);
        $this->assertTrue($this->lastSshTask()['enabled']);

        $reseller = User::factory()->reseller()->create(['reseller_package_id' => ResellerPackage::factory()->create(['ssh' => false])->id]);
        $accounts->transfer($this->account, $reseller, Package::factory()->for($reseller, 'owner')->create(['ssh' => true]), $this->admin);
        $this->assertFalse($this->lastSshTask()['enabled']);

        $accounts->changeResellerPackage($reseller, ResellerPackage::factory()->create(['ssh' => true]), $this->admin);
        $this->assertTrue($this->lastSshTask()['enabled']);
    }

    public function test_unsuspending_gives_the_shell_back_after_the_account_is_unlocked()
    {
        $this->account->forceFill(['ssh_enabled' => true])->save();
        $accounts = app(AccountService::class);

        $accounts->suspend($this->account, null, $this->admin);
        $this->assertSame(['account.suspend'], Task::query()->pluck('action')->all());

        $accounts->unsuspend($this->account->fresh(), $this->admin);

        $this->assertSame(['account.suspend', 'account.unsuspend', 'account.set_ssh'], Task::query()->orderBy('id')->pluck('action')->all());
        $this->assertTrue($this->lastSshTask()['enabled']);
    }

    public function test_keys_are_added_and_removed_and_every_key_is_written()
    {
        $this->actingAs($this->account)
            ->post(route('ssh.keys.store'), ['name' => ' Laptop ', 'public_key' => '  '.self::ED25519."\n"])
            ->assertSessionHasNoErrors();

        $key = SshKey::query()->sole();
        $this->assertSame('Laptop', $key->name);
        $this->assertSame(self::ED25519, $key->public_key);
        $this->assertSame(self::ED25519_FINGERPRINT, $key->fingerprint);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'ssh_key.added']);

        $this->actingAs($this->account)
            ->post(route('ssh.keys.store'), ['name' => 'Server', 'public_key' => self::RSA_3072])
            ->assertSessionHasNoErrors();

        $task = Task::query()->latest('id')->first();
        $this->assertSame('account.ssh_keys', $task->action);
        $this->assertSame(['username' => 'budi', 'keys' => [self::ED25519, self::RSA_3072]], $task->payload);
        $this->assertSame(self::RSA_3072_FINGERPRINT, SshKey::query()->where('name', 'Server')->value('fingerprint'));

        $this->actingAs($this->account)
            ->post(route('ssh.keys.store'), ['name' => 'Again', 'public_key' => self::ED25519.' other comment'])
            ->assertSessionHasErrors(['public_key' => 'This key is added already.']);

        $this->actingAs($this->account)
            ->delete(route('ssh.keys.destroy', $key))
            ->assertSessionHasNoErrors();

        $this->assertModelMissing($key);
        $this->assertSame([self::RSA_3072], Task::query()->latest('id')->first()->payload['keys']);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'ssh_key.removed']);
    }

    public function test_keys_work_without_ssh_for_sftp()
    {
        $this->package->update(['ssh' => false]);

        $this->actingAs($this->account)
            ->post(route('ssh.keys.store'), ['name' => 'Laptop', 'public_key' => self::ECDSA])
            ->assertSessionHasNoErrors();

        $this->assertSame(self::ECDSA_FINGERPRINT, SshKey::query()->sole()->fingerprint);
    }

    #[DataProvider('invalidKeys')]
    public function test_only_plain_public_keys_are_accepted(string $publicKey, string $message)
    {
        $this->actingAs($this->account)
            ->post(route('ssh.keys.store'), ['name' => 'Laptop', 'public_key' => $publicKey])
            ->assertSessionHasErrors('public_key');

        $this->assertStringContainsString($message, session('errors')->first('public_key'));
        $this->assertDatabaseCount('ssh_keys', 0);
        $this->assertDatabaseCount('tasks', 0);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidKeys(): array
    {
        return [
            'a private key' => ["-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXk=\n-----END OPENSSH PRIVATE KEY-----", 'private key'],
            'a forced command' => ['command="/bin/sh" '.self::ED25519, 'options'],
            'two keys' => [self::ED25519."\n".self::ECDSA, 'one line'],
            'rsa below 2048 bits' => [self::RSA_1024, '2048'],
            'dsa' => ['ssh-dss AAAAB3NzaC1kc3MAAACBAP', 'not a public key'],
            'a type that lies' => ['ssh-rsa '.substr(self::ED25519, strlen('ssh-ed25519 ')), 'damaged'],
            'cut short' => [substr(self::ED25519, 0, 60), 'damaged'],
            'a control character' => [self::ED25519."\e[31m", 'printable'],
        ];
    }

    public function test_an_account_has_at_most_twenty_keys()
    {
        SshKey::factory()->for($this->account, 'account')->count(20)->create();

        $this->actingAs($this->account)
            ->post(route('ssh.keys.store'), ['name' => 'One more', 'public_key' => SshKeyFactory::ed25519Line()])
            ->assertSessionHasErrors(['public_key' => 'An account may have at most 20 keys.']);
    }

    public function test_other_accounts_keys_are_not_found()
    {
        $foreign = SshKey::factory()->create();

        $this->actingAs($this->account)
            ->delete(route('ssh.keys.destroy', $foreign))
            ->assertNotFound();

        $this->assertModelExists($foreign);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_only_hosting_accounts_have_the_ssh_page()
    {
        $this->actingAs($this->admin)->get(route('ssh.index'))->assertForbidden();
        $this->actingAs($this->admin)->put(route('ssh.access.update'), ['enabled' => true])->assertForbidden();
    }

    /**
     * The payload of the latest account.set_ssh task.
     *
     * @return array<string, mixed>
     */
    private function lastSshTask(): array
    {
        return Task::query()->where('action', 'account.set_ssh')->latest('id')->firstOrFail()->payload;
    }

    /**
     * The package as it is, for an update form.
     *
     * @return array<string, mixed>
     */
    private function packageFields(): array
    {
        return [
            'name' => $this->package->name,
            'description' => $this->package->description,
            ...collect(Package::LIMITS)->mapWithKeys(fn (string $key) => [$key => $this->package->limit($key)])->all(),
            'ssl' => $this->package->ssl ? '1' : '0',
        ];
    }
}
