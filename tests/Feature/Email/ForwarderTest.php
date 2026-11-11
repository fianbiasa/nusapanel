<?php

namespace Tests\Feature\Email;

use App\Enums\AccountStatus;
use App\Enums\MailDomainStatus;
use App\Enums\TaskStatus;
use App\Events\AgentTaskFinished;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\MailDomain;
use App\Models\MailForwarder;
use App\Models\Package;
use App\Models\Task;
use App\Models\User;
use App\Services\DomainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ForwarderTest extends TestCase
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

    public function test_a_domains_first_forwarder_sets_up_its_mail_and_postfix_then_forwards_it()
    {
        $this->actingAs($this->account)
            ->post(route('email.forwarders.store'), [
                'local_part' => ' Sales ',
                'domain_id' => $this->domain->id,
                'destinations' => "budi@gmail.com\nTim@Example.org, budi@gmail.com",
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('email.index'));

        $forwarder = MailForwarder::query()->sole();
        $this->assertSame('sales', $forwarder->local_part);
        $this->assertSame(['budi@gmail.com', 'tim@example.org'], $forwarder->destinationList());
        $this->assertSame(
            ['username' => 'budi', 'domain' => 'example.com', 'selector' => 'rayxo'],
            Task::query()->where('action', 'mail.domain_apply')->sole()->payload,
        );
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'mail_forwarder.created', 'actor_id' => $this->account->id]);

        // Postfix forwards once the agent has prepared the domain.
        $this->assertDatabaseCount('v_mail_forwarders', 0);
        $task = Task::query()->where('action', 'mail.domain_apply')->sole();
        $task->forceFill(['status' => TaskStatus::Success, 'output' => ['dkim_public_key' => 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAtest']])->save();
        event(new AgentTaskFinished($task));

        $this->assertSame(
            [['source' => 'sales@example.com', 'destination' => 'budi@gmail.com,tim@example.org']],
            DB::table('v_mail_forwarders')->get()->map(fn (object $row): array => (array) $row)->all(),
        );
    }

    public function test_a_forwarder_on_a_mailboxs_address_leaves_the_mailbox_a_copy()
    {
        $mailDomain = MailDomain::factory()->for($this->domain)->create();
        Mailbox::factory()->for($mailDomain)->create(['local_part' => 'info']);
        MailForwarder::factory()->for($mailDomain)->create(['local_part' => 'info', 'destinations' => 'budi@gmail.com']);

        $this->assertSame(
            'info@example.com,budi@gmail.com',
            DB::table('v_mail_forwarders')->where('source', 'info@example.com')->value('destination'),
        );

        $this->actingAs($this->account)
            ->get(route('email.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('forwarders.0.address', 'info@example.com')
                ->where('forwarders.0.domain', 'example.com')
                ->where('forwarders.0.destinations', ['budi@gmail.com'])
                ->where('forwarders.0.keeps_copy', true)
                ->where('domains.0.mail_domain_id', $mailDomain->id)
                ->where('domains.0.catchall_target', null));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidForwarders(): array
    {
        return [
            'bad name' => [['local_part' => 'sales!'], 'local_part'],
            'no destinations' => [['destinations' => " \n "], 'destinations'],
            'not an address' => [['destinations' => 'budi@gmail.com, not-an-address'], 'destinations'],
            'no top-level domain' => [['destinations' => 'budi@localhost'], 'destinations'],
            'too many' => [['destinations' => implode("\n", array_map(fn (int $i): string => "a{$i}@example.org", range(1, 21)))], 'destinations'],
            'to itself' => [['destinations' => 'sales@example.com'], 'destinations'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('invalidForwarders')]
    public function test_invalid_forwarders_are_refused(array $data, string $error)
    {
        $this->actingAs($this->account)
            ->post(route('email.forwarders.store'), [...['local_part' => 'sales', 'domain_id' => $this->domain->id, 'destinations' => 'budi@gmail.com'], ...$data])
            ->assertSessionHasErrors($error);

        $this->assertDatabaseCount('mail_forwarders', 0);
        $this->assertDatabaseCount('mail_domains', 0);
    }

    public function test_an_address_has_one_forwarder_and_the_package_limits_them()
    {
        $other = Domain::factory()->create();

        $this->actingAs($this->account)
            ->post(route('email.forwarders.store'), ['local_part' => 'sales', 'domain_id' => $other->id, 'destinations' => 'budi@gmail.com'])
            ->assertSessionHasErrors(['domain_id' => 'Choose a domain of this account.']);

        $this->actingAs($this->account)
            ->post(route('email.forwarders.store'), ['local_part' => 'sales', 'domain_id' => $this->domain->id, 'destinations' => 'budi@gmail.com'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->account)
            ->post(route('email.forwarders.store'), ['local_part' => 'sales', 'domain_id' => $this->domain->id, 'destinations' => 'tim@gmail.com'])
            ->assertSessionHasErrors(['local_part' => 'This address already has a forwarder.']);

        $this->account->forceFill(['package_id' => Package::factory()->create(['email_forwarders' => 1])->id])->save();

        $this->actingAs($this->account)
            ->post(route('email.forwarders.store'), ['local_part' => 'info', 'domain_id' => $this->domain->id, 'destinations' => 'budi@gmail.com'])
            ->assertSessionHasErrors(['local_part' => 'Your package allows 1 email forwarders. Remove one or ask your provider for a bigger package.']);

        $this->assertSame(1, MailForwarder::query()->count());
    }

    public function test_a_forwarder_can_be_changed_and_deleted_by_its_account_only()
    {
        $forwarder = MailForwarder::factory()
            ->for(MailDomain::factory()->for($this->domain))
            ->create(['local_part' => 'sales', 'destinations' => 'budi@gmail.com']);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->put(route('email.forwarders.update', $forwarder), ['destinations' => 'evil@example.org'])->assertNotFound();
        $this->actingAs($stranger)->delete(route('email.forwarders.destroy', $forwarder))->assertNotFound();

        $this->actingAs($this->account)
            ->put(route('email.forwarders.update', $forwarder), ['destinations' => 'sales@example.com'])
            ->assertSessionHasErrors(['destinations' => 'A forwarder cannot send mail to its own address.']);

        $this->actingAs($this->account)
            ->put(route('email.forwarders.update', $forwarder), ['destinations' => "tim@example.org\nbudi@gmail.com"])
            ->assertSessionHasNoErrors();

        $this->assertSame(['tim@example.org', 'budi@gmail.com'], $forwarder->fresh()->destinationList());

        $this->actingAs($this->account)
            ->delete(route('email.forwarders.destroy', $forwarder))
            ->assertSessionHasNoErrors();

        $this->assertModelMissing($forwarder);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'mail_forwarder.deleted', 'actor_id' => $this->account->id]);
    }

    public function test_a_catch_all_takes_mail_for_addresses_the_domain_does_not_have()
    {
        $mailDomain = MailDomain::factory()->for($this->domain)->create();
        Mailbox::factory()->for($mailDomain)->create(['local_part' => 'info']);

        // An address of its own domain must exist, or mail would go in circles.
        $this->actingAs($this->account)
            ->put(route('email.catch-all.update', $mailDomain), ['catchall_target' => 'nobody@example.com'])
            ->assertSessionHasErrors(['catchall_target' => 'Create nobody@example.com as an email account or forwarder first.']);

        $this->actingAs($this->account)
            ->put(route('email.catch-all.update', $mailDomain), ['catchall_target' => 'not an address'])
            ->assertSessionHasErrors('catchall_target');

        $this->actingAs($this->account)
            ->put(route('email.catch-all.update', $mailDomain), ['catchall_target' => ' Info@Example.com '])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            [['source' => '@example.com', 'destination' => 'info@example.com']],
            DB::table('v_mail_catchalls')->get()->map(fn (object $row): array => (array) $row)->all(),
        );

        $this->actingAs($this->account)
            ->put(route('email.catch-all.update', $mailDomain), ['catchall_target' => 'budi@gmail.com'])
            ->assertSessionHasNoErrors();
        $this->assertSame('budi@gmail.com', DB::table('v_mail_catchalls')->value('destination'));

        $this->actingAs($this->account)
            ->put(route('email.catch-all.update', $mailDomain), ['catchall_target' => ''])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('v_mail_catchalls', 0);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'mail_domain.catchall_updated', 'actor_id' => $this->account->id]);

        $this->actingAs(User::factory()->create())
            ->put(route('email.catch-all.update', $mailDomain), ['catchall_target' => 'evil@example.org'])
            ->assertNotFound();
    }

    public function test_the_address_a_catch_all_sends_to_stays_while_the_catch_all_does()
    {
        $mailDomain = MailDomain::factory()->for($this->domain)->create(['catchall_target' => 'info@example.com']);
        $mailbox = Mailbox::factory()->for($mailDomain)->create(['local_part' => 'info']);

        $this->actingAs($this->account)
            ->delete(route('email.destroy', $mailbox))
            ->assertSessionHasErrors(['mailbox' => 'The catch-all of example.com sends mail to this address. Change the catch-all first.']);
        $this->assertModelExists($mailbox);

        // With a forwarder on the same address, the address stays either way.
        $forwarder = MailForwarder::factory()->for($mailDomain)->create(['local_part' => 'info']);

        $this->actingAs($this->account)->delete(route('email.destroy', $mailbox))->assertSessionHasNoErrors();
        $this->assertModelMissing($mailbox);

        $this->actingAs($this->account)
            ->delete(route('email.forwarders.destroy', $forwarder))
            ->assertSessionHasErrors(['forwarder' => 'The catch-all of example.com sends mail to this address. Change the catch-all first.']);
        $this->assertModelExists($forwarder);
    }

    public function test_suspended_accounts_and_unready_domains_drop_out_of_the_alias_views()
    {
        $mailDomain = MailDomain::factory()->for($this->domain)->create(['catchall_target' => 'budi@gmail.com']);
        MailForwarder::factory()->for($mailDomain)->create();

        $this->assertDatabaseCount('v_mail_forwarders', 1);
        $this->assertDatabaseCount('v_mail_catchalls', 1);

        $mailDomain->forceFill(['status' => MailDomainStatus::Failed])->save();
        $this->assertDatabaseCount('v_mail_forwarders', 0);
        $this->assertDatabaseCount('v_mail_catchalls', 0);

        $mailDomain->forceFill(['status' => MailDomainStatus::Active])->save();
        $this->account->forceFill(['status' => AccountStatus::Suspended])->save();
        $this->assertDatabaseCount('v_mail_forwarders', 0);
        $this->assertDatabaseCount('v_mail_catchalls', 0);
    }
}
