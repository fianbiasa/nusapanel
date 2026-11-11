<?php

namespace Tests\Feature\Email;

use App\Enums\AccountStatus;
use App\Enums\MailDomainStatus;
use App\Enums\TaskStatus;
use App\Events\AgentTaskFinished;
use App\Models\AuditLog;
use App\Models\DnsRecord;
use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\MailDomain;
use App\Models\Package;
use App\Models\Task;
use App\Models\User;
use App\Services\AccountService;
use App\Services\DomainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MailboxManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $account;

    private Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['hosting.public_ip' => '203.0.113.10', 'app.url' => 'https://server.example.net:2222']);

        $this->account = User::factory()->create(['username' => 'budi', 'uid' => 1001, 'gid' => 1001]);
        $this->domain = $this->app->make(DomainService::class)->create($this->account, 'example.com', $this->account);
        Task::query()->delete();
    }

    private function createMailbox(string $localPart = 'info', array $extra = []): Mailbox
    {
        $this->actingAs($this->account)
            ->post(route('email.store'), ['local_part' => $localPart, 'domain_id' => $this->domain->id, 'password' => 'a-long-mail-password', ...$extra])
            ->assertSessionHasNoErrors();

        return Mailbox::query()->where('local_part', strtolower(trim($localPart)))->sole();
    }

    private function finishMailDomainApply(string $key = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAtest'): void
    {
        $task = Task::query()->where('action', 'mail.domain_apply')->latest('id')->first();
        $task->forceFill(['status' => TaskStatus::Success, 'output' => ['dkim_public_key' => $key]])->save();

        event(new AgentTaskFinished($task));
    }

    public function test_admins_cannot_reach_email()
    {
        $this->actingAs(User::factory()->admin()->create())->get(route('email.index'))->assertForbidden();
    }

    public function test_the_first_mailbox_of_a_domain_sets_up_its_mail()
    {
        $mailbox = $this->createMailbox(' Info ', ['quota_mb' => 500]);

        $this->assertSame(500, $mailbox->quota_mb);
        $this->assertStringStartsWith('$6$', $mailbox->password_hash);
        $this->assertTrue($mailbox->passwordMatches('a-long-mail-password'));
        $this->assertSame(MailDomainStatus::Pending, $mailbox->mailDomain->status);
        $this->assertSame(
            ['username' => 'budi', 'domain' => 'example.com', 'selector' => 'rayxo'],
            Task::query()->where('action', 'mail.domain_apply')->sole()->payload,
        );
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'mailbox.created', 'actor_id' => $this->account->id]);

        // Until the agent is done the mailbox cannot take mail.
        $this->assertDatabaseCount('v_mail_mailboxes', 0);

        $this->createMailbox('sales');
        $this->assertSame(1, Task::query()->where('action', 'mail.domain_apply')->count());

        $this->finishMailDomainApply();

        $this->assertSame(MailDomainStatus::Active, $mailbox->mailDomain->fresh()->status);
        $this->assertSame(['example.com'], DB::table('v_mail_domains')->pluck('name')->all());

        $rows = DB::table('v_mail_mailboxes')->orderBy('address')->get()->map(fn (object $row): array => (array) $row)->all();
        $this->assertSame('info@example.com', $rows[0]['address']);
        $this->assertSame('/home/budi/imap/example.com/info', $rows[0]['home']);
        $this->assertSame('*:storage=500M', $rows[0]['quota_rule']);
        $this->assertSame([1001, 1001], [(int) $rows[0]['uid'], (int) $rows[0]['gid']]);
        $this->assertNull($rows[1]['quota_rule']);

        $records = DnsRecord::query()->get()->map(fn (DnsRecord $record): string => "{$record->name} {$record->type} {$record->content}")->all();
        $this->assertContains('rayxo._domainkey.example.com TXT v=DKIM1; k=rsa; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAtest', $records);
        $this->assertContains('_dmarc.example.com TXT v=DMARC1; p=none', $records);
        $this->assertContains('example.com MX mail.example.com', $records);
        $this->assertTrue(Task::query()->where('action', 'dns.sync_zone')->exists());

        $this->actingAs($this->account)
            ->get(route('email.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('email/index')
                ->has('mailboxes', 2)
                ->where('mailboxes.0.address', 'info@example.com')
                ->where('mailboxes.0.ready', true)
                ->where('domains.0.mail_status', 'active')
                ->where('server', 'server.example.net'));
    }

    public function test_a_failed_set_up_is_tried_again_with_the_next_mailbox()
    {
        $this->createMailbox();
        $task = Task::query()->where('action', 'mail.domain_apply')->sole();
        $task->forceFill(['status' => TaskStatus::Failed])->save();
        event(new AgentTaskFinished($task));

        $this->assertSame(MailDomainStatus::Failed, MailDomain::query()->sole()->status);

        $this->createMailbox('sales');

        $this->assertSame(MailDomainStatus::Pending, MailDomain::query()->sole()->status);
        $this->assertSame(2, Task::query()->where('action', 'mail.domain_apply')->count());
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidMailboxes(): array
    {
        return [
            'empty name' => [['local_part' => ''], 'local_part'],
            'two dots' => [['local_part' => 'a..b'], 'local_part'],
            'leading dot' => [['local_part' => '.info'], 'local_part'],
            'slash' => [['local_part' => '../sari'], 'local_part'],
            'space' => [['local_part' => 'in fo'], 'local_part'],
            'short password' => [['password' => 'short'], 'password'],
            'zero quota' => [['quota_mb' => 0], 'quota_mb'],
            'unknown domain' => [['domain_id' => 999], 'domain_id'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('invalidMailboxes')]
    public function test_invalid_mailboxes_are_refused(array $data, string $error)
    {
        $this->actingAs($this->account)
            ->post(route('email.store'), [...['local_part' => 'info', 'domain_id' => $this->domain->id, 'password' => 'a-long-mail-password'], ...$data])
            ->assertSessionHasErrors($error);

        $this->assertDatabaseCount('mailboxes', 0);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_only_the_accounts_own_domains_take_mailboxes_and_the_package_limits_them()
    {
        $blog = $this->app->make(DomainService::class)->createSubdomain($this->domain, 'blog', $this->account);
        $other = Domain::factory()->create();

        foreach ([$blog->id, $other->id] as $domainId) {
            $this->actingAs($this->account)
                ->post(route('email.store'), ['local_part' => 'info', 'domain_id' => $domainId, 'password' => 'a-long-mail-password'])
                ->assertSessionHasErrors(['domain_id' => 'Choose a domain of this account.']);
        }

        $this->createMailbox('info');
        $this->account->forceFill(['package_id' => Package::factory()->create(['email_accounts' => 1])->id])->save();

        $this->actingAs($this->account)
            ->post(route('email.store'), ['local_part' => 'sales', 'domain_id' => $this->domain->id, 'password' => 'a-long-mail-password'])
            ->assertSessionHasErrors(['local_part' => 'Your package allows 1 email accounts. Remove one or ask your provider for a bigger package.']);

        $this->actingAs($this->account)
            ->post(route('email.store'), ['local_part' => 'info', 'domain_id' => $this->domain->id, 'password' => 'a-long-mail-password'])
            ->assertSessionHasErrors('local_part');

        $this->assertSame(1, Mailbox::query()->count());
    }

    public function test_a_mailbox_can_be_changed_and_deleted_by_its_account_only()
    {
        $mailbox = $this->createMailbox();
        $original = $mailbox->password_hash;

        $intruder = User::factory()->create();
        $this->actingAs($intruder)->put(route('email.update', $mailbox), ['password' => 'another-long-password'])->assertNotFound();
        $this->actingAs($intruder)->delete(route('email.destroy', $mailbox))->assertNotFound();

        $this->actingAs($this->account)
            ->put(route('email.update', $mailbox), ['password' => '', 'quota_mb' => 200])
            ->assertSessionHasNoErrors();
        $this->assertSame([200, $original], [$mailbox->fresh()->quota_mb, $mailbox->fresh()->password_hash]);

        $this->actingAs($this->account)
            ->put(route('email.update', $mailbox), ['password' => 'another-long-password', 'quota_mb' => ''])
            ->assertSessionHasNoErrors();
        $this->assertTrue($mailbox->fresh()->passwordMatches('another-long-password'));
        $this->assertNull($mailbox->fresh()->quota_mb);

        $this->actingAs($this->account)
            ->delete(route('email.destroy', $mailbox))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Email account info@example.com is deleted.');

        $this->assertModelMissing($mailbox);
        $this->assertSame(
            ['username' => 'budi', 'domain' => 'example.com', 'local_part' => 'info'],
            Task::query()->where('action', 'mail.mailbox_remove')->sole()->payload,
        );
    }

    public function test_the_local_sendmail_lets_an_account_send_only_from_its_own_domains()
    {
        Domain::factory()->for($this->account, 'account')->create(['name' => 'example.net']);
        Domain::factory()->for(User::factory()->admin()->create(['username' => 'boss']), 'account')->create(['name' => 'admin.example']);
        Domain::factory()->create(['name' => 'someone-else.example']);

        $senders = DB::table('v_mail_local_senders')->where('login', 'budi')->value('senders');

        $tokens = explode(' ', (string) $senders);
        sort($tokens);
        // No empty sender (<>): such mail would name no account and escape
        // the daily send limit.
        $this->assertSame(['@example.com', '@example.net', 'budi'], $tokens);
        $this->assertNull(DB::table('v_mail_local_senders')->where('login', 'boss')->value('senders'));
    }

    public function test_suspended_accounts_drop_out_of_the_mail_views()
    {
        $this->createMailbox();
        $this->finishMailDomainApply();
        $this->account->forceFill(['status' => AccountStatus::Suspended])->save();

        $this->assertDatabaseCount('v_mail_domains', 0);
        $this->assertDatabaseCount('v_mail_mailboxes', 0);
    }

    public function test_deleting_the_domain_or_the_account_removes_its_mail()
    {
        $this->createMailbox();
        $this->finishMailDomainApply();

        $this->app->make(AccountService::class)->delete($this->account, User::factory()->admin()->create());

        $this->assertSame(
            ['mail.domain_remove example.com', 'account.delete budi'],
            Task::query()->whereIn('action', ['mail.domain_remove', 'account.delete'])->orderBy('id')->get()
                ->map(fn (Task $task): string => $task->action.' '.($task->action === 'account.delete' ? $task->payload['username'] : $task->payload['domain']))->all(),
        );

        Task::query()->delete();
        $this->account->forceFill(['status' => AccountStatus::Active])->save();

        $this->app->make(DomainService::class)->completeRemoval($this->domain->fresh(), $this->account);

        $this->assertSame(['username' => 'budi', 'domain' => 'example.com'], Task::query()->where('action', 'mail.domain_remove')->sole()->payload);
        $this->assertDatabaseCount('mail_domains', 0);
        $this->assertDatabaseCount('mailboxes', 0);
    }
}
