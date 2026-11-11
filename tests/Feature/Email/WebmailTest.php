<?php

namespace Tests\Feature\Email;

use App\Enums\MailDomainStatus;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\MailDomain;
use App\Models\User;
use App\Models\WebmailSession;
use App\Services\MailService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WebmailTest extends TestCase
{
    use RefreshDatabase;

    private string $key;

    private User $account;

    private Domain $domain;

    private Mailbox $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);

        config([
            'hosting.webmail.url' => 'https://server.test/webmail/',
            'hosting.webmail.sso_key' => base64_encode($this->key),
            'hosting.webmail.session_hours' => 12,
        ]);

        $this->account = User::factory()->create(['username' => 'budi', 'uid' => 1001, 'gid' => 1001]);
        $this->domain = Domain::factory()->for($this->account, 'account')->create(['name' => 'example.com']);
        $this->mailbox = Mailbox::factory()
            ->for(MailDomain::factory()->for($this->domain))
            ->create(['local_part' => 'info']);
    }

    public function test_opening_webmail_signs_the_mailbox_in_with_a_new_session()
    {
        $this->freezeSecond();

        $response = $this->actingAs($this->account)
            ->postJson(route('email.webmail', $this->mailbox))
            ->assertOk()
            ->assertJsonPath('url', 'https://server.test/webmail/?_task=login')
            ->assertHeader('Cache-Control', 'no-store, private');

        $cookie = $response->getCookie('rayxo_webmail_binding', decrypt: false);
        $this->assertNotNull($cookie);
        $this->assertSame('/webmail/', $cookie->getPath());
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('strict', $cookie->getSameSite());

        $ticket = $this->openTicket($response->json('ticket'));
        $this->assertSame('info@example.com', $ticket['user']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $ticket['password']);
        $this->assertSame($cookie->getValue(), $ticket['binding']);
        $this->assertSame(now()->addMinute()->getTimestamp(), $ticket['expires']);

        // The panel keeps only the token's hash, which Dovecot compares.
        $session = WebmailSession::query()->sole();
        $this->assertSame($this->mailbox->id, $session->mailbox_id);
        $this->assertSame(hash('sha256', $ticket['password']), $session->token_hash);
        $this->assertTrue($session->expires_at->equalTo(now()->addHours(12)));
        $this->assertSame(
            [['address' => 'info@example.com', 'token_hash' => $session->token_hash]],
            DB::table('v_mail_webmail_sessions')->get()->map(fn (object $row): array => (array) $row)->all(),
        );

        $this->assertDatabaseHas(AuditLog::class, ['action' => 'mailbox.webmail_opened', 'actor_id' => $this->account->id]);
    }

    public function test_every_opening_gets_another_token_and_a_mailbox_keeps_its_ten_newest_sessions()
    {
        $oldest = WebmailSession::factory()->for($this->mailbox)->create();
        WebmailSession::factory()->count(9)->for($this->mailbox)->create();

        $first = $this->actingAs($this->account)->postJson(route('email.webmail', $this->mailbox))->assertOk()->json('ticket');
        $second = $this->actingAs($this->account)->postJson(route('email.webmail', $this->mailbox))->assertOk()->json('ticket');

        $this->assertNotSame($this->openTicket($first)['password'], $this->openTicket($second)['password']);
        $this->assertSame(10, $this->mailbox->webmailSessions()->count());
        $this->assertModelMissing($oldest);
    }

    public function test_dovecot_only_sees_unexpired_sessions_of_mailboxes_it_signs_in()
    {
        $valid = WebmailSession::factory()->for($this->mailbox)->create();
        WebmailSession::factory()->expired()->for($this->mailbox)->create();

        $suspended = User::factory()->suspended()->create(['uid' => 1002, 'gid' => 1002]);
        WebmailSession::factory()
            ->for(Mailbox::factory()->for(MailDomain::factory()->for(Domain::factory()->for($suspended, 'account'))))
            ->create();

        $pendingDomain = Domain::factory()->for($this->account, 'account')->create(['name' => 'example.net']);
        WebmailSession::factory()
            ->for(Mailbox::factory()->for(MailDomain::factory()->for($pendingDomain)->state(['status' => MailDomainStatus::Pending])))
            ->create();

        $this->assertSame([$valid->token_hash], DB::table('v_mail_webmail_sessions')->pluck('token_hash')->all());
    }

    public function test_only_the_owner_can_open_a_mailboxs_webmail()
    {
        $this->actingAs(User::factory()->create())
            ->postJson(route('email.webmail', $this->mailbox))
            ->assertNotFound();

        $this->assertDatabaseCount('webmail_sessions', 0);
    }

    public function test_webmail_is_refused_until_it_is_set_up_and_the_mailbox_is_ready()
    {
        $this->mailbox->mailDomain->forceFill(['status' => MailDomainStatus::Pending])->save();

        $this->actingAs($this->account)
            ->postJson(route('email.webmail', $this->mailbox))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['webmail' => 'not ready']);

        $this->mailbox->mailDomain->forceFill(['status' => MailDomainStatus::Active])->save();
        config(['hosting.webmail.sso_key' => null]);

        $this->actingAs($this->account)
            ->postJson(route('email.webmail', $this->mailbox))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['webmail' => 'not set up']);

        $this->assertDatabaseCount('webmail_sessions', 0);
    }

    public function test_a_new_password_ends_the_webmail_sessions()
    {
        WebmailSession::factory()->count(2)->for($this->mailbox)->create();

        $this->actingAs($this->account)
            ->put(route('email.update', $this->mailbox), ['quota_mb' => 100])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $this->mailbox->webmailSessions()->count());

        $this->actingAs($this->account)
            ->put(route('email.update', $this->mailbox), ['password' => 'another-long-password'])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $this->mailbox->webmailSessions()->count());
    }

    public function test_what_webmail_kept_of_deleted_mailboxes_is_cleared()
    {
        config(['database.connections.webmail' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        Schema::connection('webmail')->create('users', function (Blueprint $table) {
            $table->id('user_id');
            $table->string('username');
        });
        DB::connection('webmail')->table('users')->insert([
            ['username' => 'info@example.com'],
            ['username' => 'sales@example.com'],
            ['username' => 'other@example.org'],
        ]);
        $webmailUsers = fn (): array => DB::connection('webmail')->table('users')->orderBy('username')->pluck('username')->all();
        Mailbox::factory()->for($this->mailbox->mailDomain)->create(['local_part' => 'sales']);

        $this->actingAs($this->account)
            ->delete(route('email.destroy', $this->mailbox))
            ->assertSessionHasNoErrors();

        $this->assertSame(['other@example.org', 'sales@example.com'], $webmailUsers());

        $this->app->make(MailService::class)->removeDomain($this->domain->fresh(), $this->account);

        $this->assertSame(['other@example.org'], $webmailUsers());
    }

    public function test_expired_sessions_are_pruned()
    {
        $valid = WebmailSession::factory()->for($this->mailbox)->create();
        $expired = WebmailSession::factory()->expired()->for($this->mailbox)->create();

        $this->artisan('model:prune', ['--model' => [WebmailSession::class]])->assertSuccessful();

        $this->assertModelExists($valid);
        $this->assertModelMissing($expired);
    }

    public function test_the_email_page_offers_webmail_where_it_is_set_up()
    {
        $this->actingAs($this->account)
            ->get(route('email.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('webmail.available', true)
                ->where('webmail.url', 'https://server.test/webmail/')
                ->where('webmail.session_hours', 12));

        config(['hosting.webmail.url' => null]);

        $this->actingAs($this->account)
            ->get(route('email.index'))
            ->assertInertia(fn (Assert $page) => $page->where('webmail.available', false));
    }

    /**
     * @return array<string, mixed>
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
