<?php

namespace Tests\Feature\Email;

use App\Enums\AccountStatus;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\MailDomain;
use App\Models\MailFilter;
use App\Models\Task;
use App\Models\User;
use App\Services\MailRulesService;
use App\Services\RebuildService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MailRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $account;

    private Mailbox $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->account = User::factory()->create(['username' => 'budi', 'uid' => 1001, 'gid' => 1001]);
        $domain = Domain::factory()->for($this->account, 'account')->create(['name' => 'example.com']);
        $this->mailbox = Mailbox::factory()->for(MailDomain::factory()->for($domain))->create(['local_part' => 'info']);
    }

    /**
     * The script of the last mail.sieve_apply task, after checking it is
     * for this mailbox.
     */
    private function lastScript(): string
    {
        $payload = Task::query()->where('action', 'mail.sieve_apply')->latest('id')->firstOrFail()->payload;

        $this->assertSame(['username' => 'budi', 'domain' => 'example.com', 'local_part' => 'info'], array_diff_key($payload, ['script' => true]));

        return $payload['script'];
    }

    public function test_an_automatic_reply_becomes_a_vacation_in_the_mailboxs_sieve_script()
    {
        $this->actingAs($this->account)
            ->put(route('email.autoreply.update', $this->mailbox), [
                'enabled' => '1',
                'subject' => 'Sedang "cuti"',
                'body' => "Terima kasih.\r\nSaya kembali tanggal 20 \\ 21.",
                'interval_days' => 3,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            "# Written by RAYXO from the Email page; changes made here are lost.\n"
            ."require [\"vacation\"];\n"
            ."\n"
            ."# Automatic reply\n"
            .'vacation :days 3 :from "info@example.com" :addresses ["info@example.com"] :subject "Sedang \\"cuti\\"" "Terima kasih.'."\n".'Saya kembali tanggal 20 \\\\ 21.";'."\n",
            $this->lastScript(),
        );
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'mailbox.autoreply_updated', 'actor_id' => $this->account->id]);
    }

    public function test_an_automatic_reply_with_dates_only_answers_between_them()
    {
        $this->actingAs($this->account)
            ->put(route('email.autoreply.update', $this->mailbox), [
                'enabled' => '1',
                'subject' => 'Cuti',
                'body' => 'Sampai jumpa.',
                'starts_on' => '2026-12-20',
                'ends_on' => '2027-01-02',
                'interval_days' => 1,
            ])
            ->assertSessionHasNoErrors();

        $script = $this->lastScript();
        $this->assertStringContainsString('require ["date", "relational", "vacation"];', $script);
        $this->assertStringContainsString('if allof (currentdate :value "ge" "date" "2026-12-20", currentdate :value "le" "date" "2027-01-02") {', $script);
    }

    public function test_turning_everything_off_removes_the_script()
    {
        $this->actingAs($this->account)
            ->put(route('email.autoreply.update', $this->mailbox), ['enabled' => '0', 'subject' => '', 'body' => '', 'interval_days' => 1])
            ->assertSessionHasNoErrors();

        $this->assertSame('', $this->lastScript());
        $this->assertFalse($this->mailbox->autoreply->enabled);
    }

    public function test_filters_run_in_order_with_every_value_quoted()
    {
        $this->actingAs($this->account)
            ->post(route('email.filters.store', $this->mailbox), ['field' => 'subject', 'operator' => 'contains', 'value' => 'invoice "2026"', 'action' => 'move', 'target' => 'Tagihan 2026'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->account)
            ->post(route('email.filters.store', $this->mailbox), ['field' => 'from', 'operator' => 'is', 'value' => 'boss@example.org', 'action' => 'forward', 'target' => ' Me@Gmail.com '])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->account)
            ->post(route('email.filters.store', $this->mailbox), ['field' => 'to', 'operator' => 'contains', 'value' => 'newsletter', 'action' => 'discard', 'target' => 'ignored'])
            ->assertSessionHasNoErrors();

        [$move, $forward, $discard] = MailFilter::query()->orderBy('id')->pluck('id')->all();

        $this->assertSame(
            "# Written by RAYXO from the Email page; changes made here are lost.\n"
            ."require [\"copy\", \"fileinto\", \"mailbox\"];\n"
            ."\n"
            ."# Filter {$move}\n"
            ."if header :contains \"subject\" \"invoice \\\"2026\\\"\" {\n"
            ."    fileinto :create \"Tagihan 2026\";\n"
            ."    stop;\n"
            ."}\n"
            ."\n"
            ."# Filter {$forward}\n"
            ."if address :all :is \"from\" \"boss@example.org\" {\n"
            ."    redirect :copy \"me@gmail.com\";\n"
            ."}\n"
            ."\n"
            ."# Filter {$discard}\n"
            ."if address :all :contains [\"to\", \"cc\"] \"newsletter\" {\n"
            ."    discard;\n"
            ."    stop;\n"
            ."}\n",
            $this->lastScript(),
        );
        $this->assertNull(MailFilter::query()->find($discard)->target);

        $this->actingAs($this->account)
            ->delete(route('email.filters.destroy', $forward))
            ->assertSessionHasNoErrors();

        $this->assertStringNotContainsString('redirect', $this->lastScript());
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'mailbox.filter_deleted', 'actor_id' => $this->account->id]);
    }

    /**
     * @return array<string, array{string, array<string, mixed>, string}>
     */
    public static function invalidRules(): array
    {
        return [
            'reply without subject' => ['autoreply', ['enabled' => '1', 'subject' => '', 'body' => 'Hi', 'interval_days' => 1], 'subject'],
            'subject on two lines' => ['autoreply', ['enabled' => '1', 'subject' => "a\nb", 'body' => 'Hi', 'interval_days' => 1], 'subject'],
            'ends before it starts' => ['autoreply', ['enabled' => '1', 'subject' => 'a', 'body' => 'Hi', 'starts_on' => '2026-12-20', 'ends_on' => '2026-12-19', 'interval_days' => 1], 'ends_on'],
            'interval too long' => ['autoreply', ['enabled' => '1', 'subject' => 'a', 'body' => 'Hi', 'interval_days' => 31], 'interval_days'],
            'unknown field' => ['filter', ['field' => 'body', 'operator' => 'contains', 'value' => 'x', 'action' => 'discard'], 'field'],
            'value on two lines' => ['filter', ['field' => 'subject', 'operator' => 'contains', 'value' => "a\nb", 'action' => 'discard'], 'value'],
            'folder with a slash' => ['filter', ['field' => 'subject', 'operator' => 'contains', 'value' => 'x', 'action' => 'move', 'target' => 'a/b'], 'target'],
            'hidden folder' => ['filter', ['field' => 'subject', 'operator' => 'contains', 'value' => 'x', 'action' => 'move', 'target' => '.x'], 'target'],
            'no folder' => ['filter', ['field' => 'subject', 'operator' => 'contains', 'value' => 'x', 'action' => 'move', 'target' => ''], 'target'],
            'not an address' => ['filter', ['field' => 'subject', 'operator' => 'contains', 'value' => 'x', 'action' => 'forward', 'target' => 'nobody'], 'target'],
            'forward to itself' => ['filter', ['field' => 'subject', 'operator' => 'contains', 'value' => 'x', 'action' => 'forward', 'target' => 'info@example.com'], 'target'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('invalidRules')]
    public function test_invalid_rules_are_refused(string $kind, array $data, string $error)
    {
        $route = $kind === 'autoreply'
            ? route('email.autoreply.update', $this->mailbox)
            : route('email.filters.store', $this->mailbox);

        $this->actingAs($this->account)
            ->call($kind === 'autoreply' ? 'PUT' : 'POST', $route, $data)
            ->assertSessionHasErrors($error);

        $this->assertDatabaseCount('mail_autoreplies', 0);
        $this->assertDatabaseCount('mail_filters', 0);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_a_mailbox_has_at_most_fifty_filters()
    {
        MailFilter::factory()->count(MailRulesService::MAX_FILTERS)->for($this->mailbox)->create();

        $this->actingAs($this->account)
            ->post(route('email.filters.store', $this->mailbox), ['field' => 'subject', 'operator' => 'contains', 'value' => 'x', 'action' => 'discard'])
            ->assertSessionHasErrors(['value' => 'An email account can have at most 50 filters.']);
    }

    public function test_only_the_owner_reaches_a_mailboxs_rules()
    {
        $filter = MailFilter::factory()->for($this->mailbox)->create();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get(route('email.rules', $this->mailbox))->assertNotFound();
        $this->actingAs($stranger)->put(route('email.autoreply.update', $this->mailbox), ['enabled' => '0', 'interval_days' => 1])->assertNotFound();
        $this->actingAs($stranger)->post(route('email.filters.store', $this->mailbox), ['field' => 'subject', 'operator' => 'contains', 'value' => 'x', 'action' => 'discard'])->assertNotFound();
        $this->actingAs($stranger)->delete(route('email.filters.destroy', $filter))->assertNotFound();

        $this->assertModelExists($filter);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_the_rules_page_shows_the_reply_and_filters()
    {
        $autoreply = $this->mailbox->autoreply()->make();
        $autoreply->forceFill(['enabled' => true, 'subject' => 'Cuti', 'body' => 'Hi', 'starts_on' => '2026-12-20', 'interval_days' => 2])->save();
        MailFilter::factory()->for($this->mailbox)->create(['value' => 'invoice', 'target' => 'Tagihan']);

        $this->actingAs($this->account)
            ->get(route('email.rules', $this->mailbox))
            ->assertInertia(fn (Assert $page) => $page
                ->component('email/rules')
                ->where('mailbox.address', 'info@example.com')
                ->where('autoreply.subject', 'Cuti')
                ->where('autoreply.starts_on', '2026-12-20')
                ->where('autoreply.ends_on', null)
                ->where('filters.0.target', 'Tagihan')
                ->where('max_filters', MailRulesService::MAX_FILTERS));
    }

    public function test_a_rebuild_writes_the_scripts_of_mailboxes_with_rules_again()
    {
        MailFilter::factory()->for($this->mailbox)->create();
        Mailbox::factory()->for($this->mailbox->mailDomain)->create(['local_part' => 'plain']);
        $suspended = User::factory()->suspended()->create();
        MailFilter::factory()->for(Mailbox::factory()->for(MailDomain::factory()->for(Domain::factory()->for($suspended, 'account'))))->create();
        $this->account->forceFill(['status' => AccountStatus::Active])->save();

        $this->app->make(RebuildService::class)->queueAll();

        $this->assertSame(2, Task::query()->where('action', 'mail.sieve_apply')->count());
        $this->assertStringContainsString('fileinto :create "Invoices";', Task::query()->where('action', 'mail.sieve_apply')->orderBy('id')->firstOrFail()->payload['script']);
    }
}
