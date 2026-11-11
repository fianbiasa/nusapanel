<?php

namespace Tests\Feature\Email;

use App\Enums\AccountStatus;
use App\Enums\MailDomainMode;
use App\Enums\MailDomainStatus;
use App\Enums\MailProvider;
use App\Enums\TaskStatus;
use App\Events\AgentTaskFinished;
use App\Models\AuditLog;
use App\Models\DnsRecord;
use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\MailDomain;
use App\Models\MailForwarder;
use App\Models\Task;
use App\Models\User;
use App\Services\DomainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MailModeTest extends TestCase
{
    use RefreshDatabase;

    private User $account;

    private Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['hosting.public_ip' => '203.0.113.10']);

        $this->account = User::factory()->create(['username' => 'budi', 'uid' => 1001, 'gid' => 1001]);
        $this->domain = $this->app->make(DomainService::class)->create($this->account, 'example.com', $this->account);
        Task::query()->delete();
    }

    /**
     * The domain's MX records, as "<priority> <host>".
     *
     * @return list<string>
     */
    private function exchangers(): array
    {
        return DnsRecord::query()->where('name', 'example.com')->where('type', 'MX')->orderBy('priority')->get()
            ->map(fn (DnsRecord $record): string => "{$record->priority} {$record->content}")
            ->values()
            ->all();
    }

    private function spf(): string
    {
        return (string) DnsRecord::query()->where('name', 'example.com')->where('type', 'TXT')->where('content', 'like', 'v=spf1%')->sole()->content;
    }

    /**
     * @param  array<string, string>  $data
     */
    private function switchTo(array $data): void
    {
        $this->actingAs($this->account)
            ->from(route('email.index'))
            ->put(route('email.mode.update', $this->domain), $data)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('email.index'));
    }

    public function test_mail_for_a_domain_can_go_to_google_workspace()
    {
        $mailDomain = MailDomain::factory()->for($this->domain)->create(['catchall_target' => 'info@example.com']);
        Mailbox::factory()->for($mailDomain)->create(['local_part' => 'info']);
        MailForwarder::factory()->for($mailDomain)->create(['local_part' => 'sales', 'destinations' => 'info@example.com']);
        $this->assertSame(['10 mail.example.com'], $this->exchangers());

        $this->switchTo(['mode' => 'external', 'provider' => 'google']);

        $mailDomain->refresh();
        $this->assertSame(MailDomainMode::External, $mailDomain->mode);
        $this->assertSame(MailProvider::Google, $mailDomain->provider);
        $this->assertSame(['1 smtp.google.com'], $this->exchangers());
        $this->assertSame('v=spf1 a mx ip4:203.0.113.10 include:_spf.google.com ~all', $this->spf());
        $this->assertContains('smtp.google.com', array_column(Task::query()->where('action', 'dns.sync_zone')->sole()->payload['records'], 'content'));
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'mail_domain.mode_updated', 'actor_id' => $this->account->id]);

        // Postfix no longer takes the domain's mail; its mailbox can still sign in.
        $this->assertDatabaseCount('v_mail_domains', 0);
        $this->assertDatabaseCount('v_mail_forwarders', 0);
        $this->assertDatabaseCount('v_mail_catchalls', 0);
        $this->assertSame(['info@example.com'], DB::table('v_mail_mailboxes')->pluck('address')->all());

        $this->actingAs($this->account)
            ->get(route('email.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('domains.0.mail_mode', 'external')
                ->where('domains.0.mail_provider', 'google')
                ->where('mailboxes.0.external', true)
                ->where('forwarders.0.external', true));
    }

    public function test_coming_back_takes_out_only_what_the_panel_set()
    {
        MailDomain::factory()->for($this->domain)->create();

        $this->switchTo(['mode' => 'external', 'provider' => 'google']);
        $this->switchTo(['mode' => 'external', 'provider' => 'zoho']);

        $this->assertSame(['10 mx.zoho.com', '20 mx2.zoho.com', '50 mx3.zoho.com'], $this->exchangers());
        $this->assertSame('v=spf1 a mx ip4:203.0.113.10 include:zohomail.com ~all', $this->spf());

        $this->switchTo(['mode' => 'local']);

        $this->assertSame(['10 mail.example.com'], $this->exchangers());
        $this->assertSame('v=spf1 a mx ip4:203.0.113.10 ~all', $this->spf());
        $this->assertDatabaseCount('v_mail_domains', 1);
        $this->assertNull(MailDomain::query()->sole()->provider);
    }

    public function test_another_service_leaves_the_dns_records_to_the_customer()
    {
        MailDomain::factory()->for($this->domain)->create();
        $records = DnsRecord::query()->orderBy('id')->get(['name', 'type', 'content', 'priority'])->toArray();

        $this->switchTo(['mode' => 'external', 'provider' => 'other']);

        $this->assertSame(MailProvider::Other, MailDomain::query()->sole()->provider);
        $this->assertDatabaseCount('v_mail_domains', 0);

        $this->actingAs($this->account)
            ->from(route('email.index'))
            ->put(route('email.mode.update', $this->domain), ['mode' => 'local'])
            ->assertSessionHas('inertia.flash_data.toast.message', 'This server takes mail for example.com again. Point its MX record here if it does not yet.');

        $this->assertSame($records, DnsRecord::query()->orderBy('id')->get(['name', 'type', 'content', 'priority'])->toArray());
        $this->assertSame(0, Task::query()->where('action', 'dns.sync_zone')->count());
    }

    public function test_a_domain_without_email_gets_its_mail_settings_when_its_mail_goes_elsewhere()
    {
        $this->switchTo(['mode' => 'local']);
        $this->assertDatabaseCount(MailDomain::class, 0);

        $this->switchTo(['mode' => 'external', 'provider' => 'google']);

        $mailDomain = MailDomain::query()->sole();
        $this->assertSame(MailDomainStatus::Pending, $mailDomain->status);
        $this->assertSame(MailDomainMode::External, $mailDomain->mode);

        // Once the agent has made its DKIM key, the zone gets it, but no MX of this server.
        $task = Task::query()->where('action', 'mail.domain_apply')->sole();
        $task->forceFill(['status' => TaskStatus::Success, 'output' => ['dkim_public_key' => 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAtest']])->save();
        event(new AgentTaskFinished($task));

        $this->assertSame(['1 smtp.google.com'], $this->exchangers());
        $this->assertDatabaseHas(DnsRecord::class, ['name' => 'rayxo._domainkey.example.com', 'type' => 'TXT']);
        $this->assertDatabaseCount('v_mail_domains', 0);
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function invalidChoices(): array
    {
        return [
            'no mode' => [[], 'mode'],
            'unknown mode' => [['mode' => 'elsewhere'], 'mode'],
            'external without a service' => [['mode' => 'external'], 'provider'],
            'unknown service' => [['mode' => 'external', 'provider' => 'yahoo'], 'provider'],
        ];
    }

    /**
     * @param  array<string, string>  $data
     */
    #[DataProvider('invalidChoices')]
    public function test_invalid_choices_are_refused(array $data, string $error)
    {
        $this->actingAs($this->account)
            ->put(route('email.mode.update', $this->domain), $data)
            ->assertSessionHasErrors($error);

        $this->assertDatabaseCount(MailDomain::class, 0);
    }

    public function test_only_an_active_owner_may_change_where_mail_goes()
    {
        $other = User::factory()->create(['username' => 'sari']);

        $this->actingAs($other)
            ->put(route('email.mode.update', $this->domain), ['mode' => 'external', 'provider' => 'google'])
            ->assertNotFound();

        $this->account->forceFill(['status' => AccountStatus::Suspended])->save();

        $this->actingAs($this->account)
            ->put(route('email.mode.update', $this->domain), ['mode' => 'external', 'provider' => 'google'])
            ->assertSessionHasErrors(['username' => 'This account is suspended or not active yet.']);

        $this->assertDatabaseCount(MailDomain::class, 0);
        $this->assertSame(['10 mail.example.com'], $this->exchangers());
    }
}
