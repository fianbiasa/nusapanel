<?php

namespace Tests\Feature\Email;

use App\Enums\TaskStatus;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Task;
use App\Models\User;
use App\Services\RebuildService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MailSendLimitsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->admin = User::factory()->admin()->create();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sentPayloads(): array
    {
        return Task::query()->where('action', 'mail.send_limits_apply')->orderBy('id')->pluck('payload')->all();
    }

    public function test_each_account_may_reach_300_recipients_a_day_until_the_admin_changes_it()
    {
        $this->actingAs($this->admin)
            ->get(route('admin.server-settings.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('mail_limits.account', 300)
                ->where('mail_limits.server', null)
                ->where('mail_limits.default_account', 300));
    }

    public function test_the_admin_sets_the_limits_and_the_mail_server_gets_them_with_each_accounts_domains()
    {
        $budi = User::factory()->create(['username' => 'budi']);
        Domain::factory()->for($budi, 'account')->create(['name' => 'example.net']);
        Domain::factory()->for($budi, 'account')->create(['name' => 'example.com']);
        User::factory()->create(['username' => 'sari']);
        Domain::factory()->for(User::factory()->reseller()->create(['username' => 'agen']), 'account')->create(['name' => 'reseller.example']);

        $this->actingAs($this->admin)
            ->put(route('admin.server-settings.mail-limits'), ['account' => 50, 'server' => 1000])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Send limits saved.');

        $this->assertSame([
            [
                'account_limit' => 50,
                'server_limit' => 1000,
                'accounts' => [
                    ['username' => 'budi', 'domains' => ['example.com', 'example.net']],
                    ['username' => 'sari', 'domains' => []],
                ],
            ],
        ], $this->sentPayloads());
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'server.mail_send_limits_changed', 'actor_id' => $this->admin->id]);

        $this->actingAs($this->admin)
            ->get(route('admin.server-settings.index'))
            ->assertInertia(fn (Assert $page) => $page->where('mail_limits.account', 50)->where('mail_limits.server', 1000));
    }

    public function test_empty_limits_mean_no_limit()
    {
        $this->actingAs($this->admin)
            ->put(route('admin.server-settings.mail-limits'), ['account' => '', 'server' => ''])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Send limits saved.');

        $this->assertSame([['account_limit' => 0, 'server_limit' => 0, 'accounts' => []]], $this->sentPayloads());

        $this->actingAs($this->admin)
            ->get(route('admin.server-settings.index'))
            ->assertInertia(fn (Assert $page) => $page->where('mail_limits.account', null)->where('mail_limits.server', null));
    }

    public function test_saving_the_same_limits_changes_nothing()
    {
        $this->actingAs($this->admin)
            ->put(route('admin.server-settings.mail-limits'), ['account' => 300, 'server' => ''])
            ->assertInertiaFlash('toast.message', 'Nothing changed.');

        $this->assertSame([], $this->sentPayloads());
        $this->assertDatabaseMissing(AuditLog::class, ['action' => 'server.mail_send_limits_changed']);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidLimits(): array
    {
        return [
            'zero' => [0],
            'negative' => [-5],
            'too large' => [10_000_001],
            'fraction' => [1.5],
            'text' => ['banyak'],
        ];
    }

    #[DataProvider('invalidLimits')]
    public function test_invalid_limits_are_refused(mixed $limit)
    {
        $this->actingAs($this->admin)
            ->put(route('admin.server-settings.mail-limits'), ['account' => $limit, 'server' => $limit])
            ->assertSessionHasErrors(['account', 'server']);

        $this->assertSame([], $this->sentPayloads());
    }

    public function test_hosting_accounts_cannot_change_the_limits()
    {
        $this->actingAs(User::factory()->create())
            ->put(route('admin.server-settings.mail-limits'), ['account' => 100000])
            ->assertForbidden();

        $this->assertSame([], $this->sentPayloads());
    }

    public function test_the_mail_server_gets_the_limits_again_only_when_they_changed_or_did_not_arrive()
    {
        $budi = User::factory()->create(['username' => 'budi']);

        $this->artisan('rayxo:sync-send-limits')->expectsOutputToContain('Send limits queued')->assertSuccessful();
        $this->artisan('rayxo:sync-send-limits')->expectsOutputToContain('already has the current send limits')->assertSuccessful();
        $this->assertCount(1, $this->sentPayloads());

        // A new domain must be limited too.
        Domain::factory()->for($budi, 'account')->create(['name' => 'example.com']);
        $this->artisan('rayxo:sync-send-limits')->assertSuccessful();
        $this->assertSame(['example.com'], $this->sentPayloads()[1]['accounts'][0]['domains']);

        // A failed task is tried again.
        Task::query()->latest('id')->first()->forceFill(['status' => TaskStatus::Failed])->save();
        $this->artisan('rayxo:sync-send-limits')->assertSuccessful();
        $this->assertCount(3, $this->sentPayloads());
    }

    public function test_a_rebuild_sends_the_limits_even_when_unchanged()
    {
        $rebuild = $this->app->make(RebuildService::class);

        $rebuild->queueAll($this->admin);
        $rebuild->queueAll($this->admin);

        $this->assertCount(2, $this->sentPayloads());
    }
}
