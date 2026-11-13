<?php

namespace Tests\Feature\Api;

use App\Enums\AccountStatus;
use App\Enums\Role;
use App\Models\LoginKey;
use App\Models\Package;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ApiAccountTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_KEY = 'rxk_admin6789abcdefghijABCDEFGHIJ0123456789';

    private const RESELLER_KEY = 'rxk_resel6789abcdefghijABCDEFGHIJ0123456789';

    private User $admin;

    private User $reseller;

    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->admin = User::factory()->admin()->create();
        $this->reseller = User::factory()->reseller()->create();
        $this->package = Package::factory()->for($this->reseller, 'owner')->create(['name' => 'Starter']);

        $all = ['accounts:read', 'accounts:write', 'packages:read'];
        LoginKey::factory()->for($this->admin)->withSecret(self::ADMIN_KEY)->create(['permissions' => $all]);
        LoginKey::factory()->for($this->reseller)->withSecret(self::RESELLER_KEY)->create(['permissions' => $all]);
    }

    public function test_hosting_accounts_are_listed_for_whoever_manages_them()
    {
        User::factory()->create(['username' => 'budi', 'owner_id' => $this->reseller->id]);
        User::factory()->create(['username' => 'sari', 'owner_id' => $this->admin->id]);
        User::factory()->create(['username' => 'tono', 'owner_id' => $this->admin->id, 'status' => AccountStatus::Suspended]);

        $this->withToken(self::ADMIN_KEY)->getJson('/api/v1/accounts')
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.*.username', ['budi', 'sari', 'tono']);

        $this->withToken(self::ADMIN_KEY)->getJson('/api/v1/accounts?status=suspended')->assertJsonPath('data.*.username', ['tono']);

        $this->withToken(self::RESELLER_KEY)->getJson('/api/v1/accounts')->assertJsonPath('data.*.username', ['budi']);
        $this->withToken(self::RESELLER_KEY)->getJson('/api/v1/accounts/sari')->assertNotFound();
        $this->withToken(self::ADMIN_KEY)->getJson('/api/v1/accounts/'.$this->reseller->username)->assertNotFound();
    }

    public function test_a_key_only_ever_makes_hosting_accounts()
    {
        $this->withToken(self::RESELLER_KEY)
            ->postJson('/api/v1/accounts', [
                'username' => 'budi',
                'name' => 'Budi',
                'email' => 'budi@example.com',
                'password' => 'a-long-password-123',
                'domain' => 'budi.example',
                'package_id' => $this->package->id,
                'role' => 'admin',
            ])
            ->assertCreated()
            ->assertJsonPath('data.username', 'budi')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.package.name', 'Starter');

        $account = User::query()->where('username', 'budi')->sole();
        $this->assertSame(Role::User, $account->role);
        $this->assertSame($this->reseller->id, $account->owner_id);
        $this->assertTrue(Task::query()->where('action', 'account.create')->exists());

        $this->withToken(self::RESELLER_KEY)
            ->postJson('/api/v1/accounts', ['username' => 'budi', 'name' => 'B', 'email' => 'b@example.com', 'password' => 'a-long-password-123'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('username');
    }

    public function test_an_account_is_suspended_moved_and_deleted()
    {
        User::factory()->create(['username' => 'budi', 'owner_id' => $this->reseller->id]);

        $this->withToken(self::RESELLER_KEY)->postJson('/api/v1/accounts/budi/suspend', ['reason' => 'Unpaid'])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.suspend_reason', 'Unpaid');

        $this->withToken(self::RESELLER_KEY)->postJson('/api/v1/accounts/budi/unsuspend')->assertOk()->assertJsonPath('data.status', 'active');

        $this->withToken(self::RESELLER_KEY)->putJson('/api/v1/accounts/budi/package', ['package_id' => $this->package->id])
            ->assertOk()
            ->assertJsonPath('data.package.id', $this->package->id);

        $this->withToken(self::RESELLER_KEY)->deleteJson('/api/v1/accounts/budi')->assertStatus(202)->assertJsonPath('data.status', 'deleting');

        $this->assertSame(
            ['account.suspend', 'account.unsuspend', 'account.delete'],
            Task::query()->whereIn('action', ['account.suspend', 'account.unsuspend', 'account.delete'])->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_reading_needs_only_the_read_permission()
    {
        LoginKey::query()->where('user_id', $this->reseller->id)->update(['permissions' => json_encode(['accounts:read'])]);
        User::factory()->create(['username' => 'budi', 'owner_id' => $this->reseller->id]);

        $this->withToken(self::RESELLER_KEY)->getJson('/api/v1/accounts/budi')->assertOk();
        $this->withToken(self::RESELLER_KEY)->postJson('/api/v1/accounts/budi/suspend')->assertForbidden();
        $this->withToken(self::RESELLER_KEY)->getJson('/api/v1/packages')->assertForbidden();
    }

    public function test_packages_are_the_ones_the_key_can_hand_out()
    {
        Package::factory()->for($this->admin, 'owner')->create(['name' => 'Admin only']);

        $this->withToken(self::RESELLER_KEY)->getJson('/api/v1/packages')
            ->assertOk()
            ->assertJsonPath('data.*.name', ['Starter'])
            ->assertJsonPath('data.0.ssl', true)
            ->assertJsonPath('data.0.resources', ['cpu_percent' => null, 'memory_mb' => null, 'max_processes' => null, 'io_weight' => null]);
    }
}
