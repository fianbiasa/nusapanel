<?php

namespace Tests\Feature\Api;

use App\Enums\AccountStatus;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\LoginKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ApiAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'rxk_0123456789abcdefghijABCDEFGHIJ0123456789';

    private User $account;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->account = User::factory()->create(['username' => 'budi']);
    }

    public function test_requests_without_a_valid_key_are_refused()
    {
        LoginKey::factory()->for($this->account)->withSecret(self::SECRET)->create(['expires_at' => now()->subMinute()]);

        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->withToken('rxk_unknown')->getJson('/api/v1/me')->assertUnauthorized();
        $this->withToken(self::SECRET)->getJson('/api/v1/me')->assertUnauthorized();

        // A panel session is not a key.
        $this->actingAs($this->account)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_a_key_acts_as_its_account_and_says_what_it_may_do()
    {
        LoginKey::factory()->for($this->account)->withSecret(self::SECRET)->create(['name' => 'Billing', 'permissions' => ['domains:read']]);

        $this->withToken(self::SECRET)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.username', 'budi')
            ->assertJsonPath('data.role', 'user')
            ->assertJsonPath('data.login_key.name', 'Billing')
            ->assertJsonPath('data.login_key.permissions', ['domains:read']);

        $key = LoginKey::query()->sole();
        $this->assertNotNull($key->last_used_at);
        $this->assertSame('127.0.0.1', $key->last_used_ip);
    }

    public function test_each_request_is_the_account_of_its_own_key()
    {
        $other = User::factory()->create(['username' => 'sari']);
        LoginKey::factory()->for($this->account)->withSecret(self::SECRET)->create();
        LoginKey::factory()->for($other)->withSecret('rxk_sari56789abcdefghijABCDEFGHIJ0123456789')->create();

        $this->withToken(self::SECRET)->getJson('/api/v1/me')->assertJsonPath('data.username', 'budi');
        $this->withToken('rxk_sari56789abcdefghijABCDEFGHIJ0123456789')->getJson('/api/v1/me')->assertJsonPath('data.username', 'sari');
        $this->withToken('rxk_unknown56789abcdefghijABCDEFGHIJ01234567')->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_a_key_works_only_from_its_addresses()
    {
        LoginKey::factory()->for($this->account)->withSecret(self::SECRET)->create(['allowed_ips' => ['203.0.113.0/24']]);

        $this->withToken(self::SECRET)->getJson('/api/v1/me')
            ->assertForbidden()
            ->assertJsonPath('message', 'This login key may not be used from 127.0.0.1.');

        $this->withToken(self::SECRET)->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])->getJson('/api/v1/me')->assertOk();
    }

    public function test_a_suspended_account_cannot_use_its_keys()
    {
        $this->account->forceFill(['status' => AccountStatus::Suspended])->save();
        LoginKey::factory()->for($this->account)->withSecret(self::SECRET)->create();

        $this->withToken(self::SECRET)->getJson('/api/v1/me')->assertForbidden();
    }

    public function test_a_key_needs_the_permission_of_each_action()
    {
        Domain::factory()->for($this->account, 'account')->create(['name' => 'example.com']);
        LoginKey::factory()->for($this->account)->withSecret(self::SECRET)->create(['permissions' => ['domains:read']]);

        $this->withToken(self::SECRET)->getJson('/api/v1/domains')->assertOk()->assertJsonPath('data.0.name', 'example.com');

        $this->withToken(self::SECRET)->postJson('/api/v1/domains', ['domain' => 'example.org'])
            ->assertForbidden()
            ->assertJsonPath('message', 'This login key does not have the domains:write permission.');

        // A hosting account's key has nothing to do with accounts.
        $this->withToken(self::SECRET)->getJson('/api/v1/accounts')->assertForbidden();
    }

    public function test_changes_through_the_api_are_recorded_with_their_key()
    {
        LoginKey::factory()->for($this->account)->withSecret(self::SECRET)->create(['name' => 'Deploy bot', 'permissions' => ['domains:write']]);

        $this->withToken(self::SECRET)->postJson('/api/v1/domains', ['domain' => 'example.org'])->assertCreated();

        $log = AuditLog::query()->where('action', 'domain.created')->sole();
        $this->assertSame($this->account->id, $log->actor_id);
        $this->assertSame('Deploy bot', $log->login_key_name);
        $this->assertSame(LoginKey::query()->sole()->id, $log->login_key_id);
    }
}
