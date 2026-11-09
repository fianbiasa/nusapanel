<?php

namespace Tests\Feature\Databases;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Agent\FakeAgentClient;
use App\Models\AuditLog;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\TestCase;

class PhpMyAdminTest extends TestCase
{
    use RefreshDatabase;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();

        $this->key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);

        config([
            'hosting.phpmyadmin.url' => 'https://server.test/phpmyadmin/',
            'hosting.phpmyadmin.sso_key' => base64_encode($this->key),
            'hosting.phpmyadmin.session_minutes' => 60,
        ]);
    }

    public function test_opening_phpmyadmin_signs_the_account_in_with_a_new_password()
    {
        $this->freezeSecond();
        $agent = $this->app->instance(AgentClient::class, new FakeAgentClient);
        $account = User::factory()->create(['username' => 'budi']);
        Database::factory()->for($account, 'account')->create(['name' => 'budi_wp']);
        Database::factory()->for($account, 'account')->create(['name' => 'budi_shop']);
        Database::factory()->for($account, 'account')->pending()->create(['name' => 'budi_new']);
        Database::factory()->create();

        $response = $this->actingAs($account)
            ->postJson(route('databases.phpmyadmin'))
            ->assertOk()
            ->assertJsonPath('url', 'https://server.test/phpmyadmin/nusapanel-sso.php')
            ->assertHeader('Cache-Control', 'no-store, private');

        $cookie = $response->getCookie('nusa_pma_binding', decrypt: false);
        $this->assertNotNull($cookie);
        $this->assertSame('/phpmyadmin/', $cookie->getPath());
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('strict', $cookie->getSameSite());

        $ticket = $this->openTicket($response->json('ticket'));
        $this->assertSame('budi', $ticket['user']);
        $this->assertSame(32, strlen($ticket['password']));
        $this->assertSame($cookie->getValue(), $ticket['binding']);
        $this->assertSame(now()->addMinute()->getTimestamp(), $ticket['expires']);

        // The agent only ever gets the hash, with the account's ready databases.
        $this->assertSame([[
            'method' => 'db.open_sso',
            'params' => [
                'username' => 'budi',
                'password_hash' => DatabaseUser::hashPassword($ticket['password']),
                'databases' => ['budi_shop', 'budi_wp'],
            ],
        ]], $agent->calls());

        $this->assertTrue($account->fresh()->phpmyadmin_expires_at->equalTo(now()->addHour()));
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'database.phpmyadmin_opened', 'actor_id' => $account->id]);
    }

    public function test_every_opening_gets_another_password()
    {
        $account = User::factory()->create();
        Database::factory()->for($account, 'account')->create();

        $first = $this->actingAs($account)->postJson(route('databases.phpmyadmin'))->json('ticket');
        $second = $this->actingAs($account)->postJson(route('databases.phpmyadmin'))->json('ticket');

        $this->assertNotSame($this->openTicket($first)['password'], $this->openTicket($second)['password']);
    }

    public function test_phpmyadmin_needs_a_ready_database()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('call'));
        $account = User::factory()->create();
        Database::factory()->for($account, 'account')->pending()->create();

        $this->actingAs($account)
            ->postJson(route('databases.phpmyadmin'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phpmyadmin' => 'Create a database first.']);

        $this->assertNull($account->fresh()->phpmyadmin_expires_at);
    }

    public function test_phpmyadmin_cannot_be_opened_where_it_is_not_set_up()
    {
        config(['hosting.phpmyadmin.sso_key' => null]);
        $account = User::factory()->create();
        Database::factory()->for($account, 'account')->create();

        $this->actingAs($account)
            ->postJson(route('databases.phpmyadmin'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phpmyadmin' => 'phpMyAdmin is not set up on this server.']);

        $this->actingAs($account)
            ->get(route('databases.index'))
            ->assertInertia(fn (Assert $page) => $page->where('phpmyadmin.available', false));
    }

    public function test_the_page_offers_phpmyadmin_where_it_is_set_up()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('databases.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('phpmyadmin.available', true)
                ->where('phpmyadmin.session_minutes', 60));
    }

    public function test_an_unreachable_agent_is_a_readable_error()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('call')->once()->andThrow(new AgentException('agent is not running')));
        $account = User::factory()->create();
        Database::factory()->for($account, 'account')->create();

        $this->actingAs($account)
            ->postJson(route('databases.phpmyadmin'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phpmyadmin' => 'phpMyAdmin could not be opened. Try again in a moment.'])
            ->assertCookieMissing('nusa_pma_binding');

        $this->assertNull($account->fresh()->phpmyadmin_expires_at);
    }

    public function test_admins_cannot_open_phpmyadmin()
    {
        $this->actingAs(User::factory()->admin()->create())
            ->postJson(route('databases.phpmyadmin'))
            ->assertForbidden();
    }

    public function test_sessions_end_when_their_time_is_up()
    {
        $agent = $this->app->instance(AgentClient::class, new FakeAgentClient);
        $ended = User::factory()->create(['username' => 'budi', 'phpmyadmin_expires_at' => now()->subMinute()]);
        $running = User::factory()->create(['username' => 'sari', 'phpmyadmin_expires_at' => now()->addMinutes(30)]);
        User::factory()->create(['username' => 'joko']);

        $this->artisan('nusapanel:close-phpmyadmin-sessions')
            ->expectsOutputToContain('1 phpMyAdmin session ended.')
            ->assertSuccessful();

        $this->assertSame([['method' => 'db.close_sso', 'params' => ['username' => 'budi']]], $agent->calls());
        $this->assertNull($ended->fresh()->phpmyadmin_expires_at);
        $this->assertNotNull($running->fresh()->phpmyadmin_expires_at);
    }

    public function test_a_session_the_agent_could_not_end_is_tried_again()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('call')->once()->with('db.close_sso', ['username' => 'budi'], 30)
            ->andThrow(new AgentException('agent is not running')));
        $account = User::factory()->create(['username' => 'budi', 'phpmyadmin_expires_at' => now()->subMinute()]);

        $this->artisan('nusapanel:close-phpmyadmin-sessions')
            ->expectsOutputToContain('0 phpMyAdmin sessions ended.')
            ->assertSuccessful();

        $this->assertNotNull($account->fresh()->phpmyadmin_expires_at);
    }

    /**
     * Open a ticket the way phpMyAdmin's sign-on script does.
     *
     * @return array{user: string, password: string, binding: string, expires: int}
     */
    private function openTicket(string $ticket): array
    {
        $sealed = sodium_base642bin($ticket, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        $json = sodium_crypto_secretbox_open(
            substr($sealed, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($sealed, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key,
        );

        $this->assertIsString($json, 'The ticket does not open with the key.');

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }
}
