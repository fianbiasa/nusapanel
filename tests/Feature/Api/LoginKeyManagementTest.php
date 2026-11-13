<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\LoginKey;
use App\Models\User;
use App\Services\LoginKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LoginKeyManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_keys_are_made_after_the_password_is_confirmed()
    {
        $account = User::factory()->create();

        $this->actingAs($account)->get(route('login-keys.index'))->assertRedirect(route('password.confirm'));
        $this->actingAs($account)->post(route('login-keys.store'), ['name' => 'Billing', 'permissions' => ['domains:read']])->assertRedirect(route('password.confirm'));

        $this->assertDatabaseCount('login_keys', 0);
    }

    public function test_a_key_is_shown_once_and_only_its_hash_is_kept()
    {
        $account = User::factory()->create();

        $response = $this->confirmed($account)
            ->post(route('login-keys.store'), [
                'name' => ' Billing ',
                'permissions' => ['domains:read', 'domains:write'],
                'allowed_ips' => "203.0.113.10\n2001:db8::/48, 198.51.100.0/24",
                'expires_at' => now()->addMonth()->format('Y-m-d'),
            ])
            ->assertSessionHasNoErrors();

        $secret = $response->getSession()->get('inertia.flash_data')['login_key']['secret'] ?? null;
        $this->assertIsString($secret);
        $this->assertMatchesRegularExpression('/^rxk_[A-Za-z0-9]{40}$/', $secret);

        $key = LoginKey::query()->sole();
        $this->assertSame('Billing', $key->name);
        $this->assertSame(hash('sha256', $secret), $key->key_hash);
        $this->assertSame(substr($secret, 0, 12), $key->key_prefix);
        $this->assertSame(['domains:read', 'domains:write'], $key->permissions);
        $this->assertSame(['203.0.113.10', '2001:db8::/48', '198.51.100.0/24'], $key->allowed_ips);
        $this->assertTrue($key->expires_at->isSameDay(now()->addMonth()));
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'login_key.created']);

        // The list never carries the key itself.
        $this->confirmed($account)
            ->get(route('login-keys.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/login-keys')
                ->where('keys.0.prefix', substr($secret, 0, 12))
                ->where('permissions', ['domains:read', 'domains:write', 'databases:read', 'databases:write', 'email:read', 'email:write', 'dns:read', 'dns:write'])
                ->missing('keys.0.key_hash'));

        $this->assertStringNotContainsString($secret, $this->confirmed($account)->get(route('login-keys.index'))->getContent());
    }

    public function test_a_key_only_gets_permissions_the_role_may_give()
    {
        $account = User::factory()->create();
        $reseller = User::factory()->reseller()->create();

        $this->confirmed($account)
            ->post(route('login-keys.store'), ['name' => 'X', 'permissions' => ['accounts:write']])
            ->assertSessionHasErrors('permissions.0');

        $this->confirmed($reseller)
            ->post(route('login-keys.store'), ['name' => 'X', 'permissions' => ['domains:write']])
            ->assertSessionHasErrors('permissions.0');

        $this->confirmed($reseller)
            ->post(route('login-keys.store'), ['name' => 'WHMCS', 'permissions' => ['accounts:read', 'accounts:write', 'packages:read']])
            ->assertSessionHasNoErrors();

        $this->assertSame(['accounts:read', 'accounts:write', 'packages:read'], LoginKey::query()->sole()->permissions);
    }

    public function test_addresses_dates_and_the_number_of_keys_are_checked()
    {
        $account = User::factory()->create();

        foreach ([
            ['allowed_ips' => 'example.com'],
            ['allowed_ips' => '203.0.113.0/33'],
            ['allowed_ips' => '2001:db8::/129'],
            ['expires_at' => now()->format('Y-m-d')],
            ['permissions' => []],
            ['name' => ''],
        ] as $change) {
            $this->confirmed($account)
                ->post(route('login-keys.store'), [...['name' => 'X', 'permissions' => ['domains:read']], ...$change])
                ->assertSessionHasErrors();
        }

        LoginKey::factory()->for($account)->count(LoginKeyService::MAX_PER_ACCOUNT)->create();

        $this->confirmed($account)
            ->post(route('login-keys.store'), ['name' => 'One more', 'permissions' => ['domains:read']])
            ->assertSessionHasErrors(['name' => 'An account may have at most 20 login keys.']);
    }

    public function test_an_account_deletes_only_its_own_keys()
    {
        $account = User::factory()->create();
        $key = LoginKey::factory()->for($account)->create();
        $foreign = LoginKey::factory()->create();

        $this->confirmed($account)->delete(route('login-keys.destroy', $foreign))->assertNotFound();
        $this->confirmed($account)->delete(route('login-keys.destroy', $key))->assertSessionHasNoErrors();

        $this->assertModelMissing($key);
        $this->assertModelExists($foreign);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'login_key.deleted']);
    }

    private function confirmed(User $user): self
    {
        return $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
    }
}
