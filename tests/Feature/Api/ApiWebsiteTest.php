<?php

namespace Tests\Feature\Api;

use App\Models\Database;
use App\Models\DnsZone;
use App\Models\Domain;
use App\Models\LoginKey;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ApiWebsiteTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'rxk_budi56789abcdefghijABCDEFGHIJ0123456789';

    private User $account;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->account = User::factory()->create(['username' => 'budi']);
        LoginKey::factory()->for($this->account)->withSecret(self::KEY)->create(['permissions' => [
            'domains:read', 'domains:write', 'databases:read', 'databases:write', 'email:read', 'email:write', 'dns:read', 'dns:write',
        ]]);
    }

    public function test_domains_are_added_and_deleted()
    {
        $this->withToken(self::KEY)->postJson('/api/v1/domains', ['domain' => 'Example.org'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'example.org')
            ->assertJsonPath('data.status', 'pending');

        $this->withToken(self::KEY)->postJson('/api/v1/domains', ['domain' => 'not a domain'])->assertUnprocessable()->assertJsonValidationErrors('domain');

        $domain = Domain::query()->where('name', 'example.org')->sole();
        $domain->forceFill(['status' => 'active'])->save();

        $this->withToken(self::KEY)->deleteJson("/api/v1/domains/{$domain->id}")->assertStatus(202)->assertJsonPath('data.status', 'deleting');
        $this->withToken(self::KEY)->deleteJson('/api/v1/domains/'.Domain::factory()->create()->id)->assertNotFound();
    }

    public function test_databases_and_their_users_are_made()
    {
        $this->withToken(self::KEY)->postJson('/api/v1/databases', ['name' => 'shop', 'create_user' => false])
            ->assertCreated()
            ->assertJsonPath('data.name', 'budi_shop');

        $database = Database::query()->sole();

        $this->withToken(self::KEY)->postJson('/api/v1/database-users', ['name' => 'app', 'password' => 'a-long-password-123', 'databases' => [$database->id]])
            ->assertCreated()
            ->assertJsonPath('data.name', 'budi_app')
            ->assertJsonPath('data.databases', ['budi_shop']);

        $this->withToken(self::KEY)->getJson('/api/v1/databases')->assertOk()->assertJsonPath('data.0.users', ['budi_app']);
        $this->assertTrue(Task::query()->where('action', 'db.create_database')->exists());

        $this->withToken(self::KEY)->deleteJson('/api/v1/databases/'.Database::factory()->create()->id)->assertNotFound();
    }

    public function test_dns_records_are_added_and_deleted_in_the_accounts_zones()
    {
        $zone = DnsZone::factory()->for($this->account, 'account')->create(['name' => 'example.com']);
        $foreign = DnsZone::factory()->create(['name' => 'other.net']);

        $this->withToken(self::KEY)
            ->postJson("/api/v1/dns/zones/{$zone->id}/records", ['type' => 'TXT', 'name' => 'api', 'content' => 'hello', 'ttl' => 3600])
            ->assertCreated()
            ->assertJsonPath('data.name', 'api.example.com')
            ->assertJsonPath('data.content', 'hello');

        $this->withToken(self::KEY)->getJson('/api/v1/dns/zones')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'example.com')
            ->assertJsonFragment(['name' => 'api.example.com', 'type' => 'TXT']);

        $this->withToken(self::KEY)
            ->postJson("/api/v1/dns/zones/{$foreign->id}/records", ['type' => 'TXT', 'name' => 'x', 'content' => 'y', 'ttl' => 3600])
            ->assertNotFound();

        $record = $zone->records()->where('name', 'api.example.com')->sole();
        $this->withToken(self::KEY)->deleteJson("/api/v1/dns/records/{$record->id}")->assertNoContent();
        $this->assertModelMissing($record);
    }

    public function test_an_administrators_key_has_no_websites()
    {
        $admin = User::factory()->admin()->create();
        LoginKey::factory()->for($admin)->withSecret('rxk_adm456789abcdefghijABCDEFGHIJ0123456789')->create(['permissions' => ['domains:read']]);

        $this->withToken('rxk_adm456789abcdefghijABCDEFGHIJ0123456789')->getJson('/api/v1/domains')->assertForbidden();
    }
}
