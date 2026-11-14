<?php

namespace Tests\Feature\Backups;

use App\Agent\AgentClient;
use App\Enums\AccountStatus;
use App\Enums\DomainType;
use App\Enums\ServerBackupStatus;
use App\Enums\TaskStatus;
use App\Events\AgentTaskFinished;
use App\Jobs\CreateServerBackup;
use App\Jobs\DeliverAlert;
use App\Jobs\RunAgentTask;
use App\Models\AccountRestore;
use App\Models\AuditLog;
use App\Models\Database;
use App\Models\Domain;
use App\Models\NotificationChannel;
use App\Models\Package;
use App\Models\ServerBackup;
use App\Models\Task;
use App\Models\User;
use App\Services\ServerBackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Backups of single accounts made by hand, what a reseller sees of the
 * backups, and deleted accounts made again from a backup.
 */
class AccountBackupRestoreTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $reseller;

    /**
     * @var list<array{0: string, 1: array<string, mixed>}>
     */
    private array $agentCalls = [];

    /**
     * @var array<string, mixed>
     */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->travelTo('2026-11-14 10:00:00');

        $this->admin = User::factory()->admin()->create();
        $this->reseller = User::factory()->reseller()->create(['username' => 'jual']);
        $this->answers = ['php.versions' => ['versions' => ['8.2', '8.4']]];

        $this->mock(AgentClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('call')->andReturnUsing(function (string $method, array $params = []): array {
                $this->agentCalls[] = [$method, $params];

                return $this->answers[$method] ?? [];
            });
        });
    }

    public function test_an_account_is_backed_up_by_hand_on_the_backups_queue()
    {
        $account = User::factory()->for($this->reseller, 'owner')->create(['username' => 'budi']);

        $this->actingAs($this->reseller)
            ->post(route('reseller.backups.store'), ['username' => 'budi'])
            ->assertSessionHasNoErrors();

        $backup = ServerBackup::query()->sole();
        $this->assertSame(['budi-2026-11-14-100000-manual.tar.gz', ServerBackupStatus::Queued, $account->id, $this->reseller->id, null], [$backup->name, $backup->status, $backup->user_id, $backup->owner_id, $backup->backup_run_id]);
        Queue::assertPushedOn(ServerBackupService::QUEUE, CreateServerBackup::class, fn (CreateServerBackup $job) => $job->withDatabases);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'backup.server_created', 'actor_id' => $this->reseller->id]);

        $this->actingAs($this->reseller)
            ->post(route('reseller.backups.store'), ['username' => 'budi'])
            ->assertSessionHasErrors(['username' => 'A backup of budi is already being made.']);
    }

    public function test_a_reseller_only_backs_up_its_own_accounts()
    {
        User::factory()->create(['username' => 'other']);
        User::factory()->for($this->reseller, 'owner')->create(['username' => 'gone', 'status' => AccountStatus::Deleting]);

        foreach (['other', 'gone', 'jual'] as $username) {
            $this->actingAs($this->reseller)
                ->post(route('reseller.backups.store'), ['username' => $username])
                ->assertSessionHasErrors(['username' => 'Choose one of your hosting accounts.']);
        }

        $this->assertSame(0, ServerBackup::query()->count());
    }

    public function test_an_account_keeps_its_newest_backups_made_by_hand()
    {
        $account = User::factory()->create(['username' => 'budi']);
        $older = ServerBackup::factory()->for($account, 'account')->count(3)->create(['backup_run_id' => null]);
        $automatic = ServerBackup::factory()->for($account, 'account')->create();
        $backup = ServerBackup::factory()->for($account, 'account')->create(['backup_run_id' => null, 'status' => ServerBackupStatus::Queued]);
        $this->answers['backup.server_create'] = ['name' => $backup->name, 'size' => 100];

        app(ServerBackupService::class)->create($backup, true);

        $this->assertSame([['names' => [$older[0]->name]]], collect($this->agentCalls)->where(0, 'backup.server_delete')->pluck(1)->values()->all());
        $this->assertModelMissing($older[0]);

        foreach ([$older[1], $older[2], $automatic, $backup] as $kept) {
            $this->assertModelExists($kept);
        }
    }

    public function test_a_reseller_sees_and_uses_only_the_backups_of_its_accounts()
    {
        $mine = ServerBackup::factory()->for(User::factory()->for($this->reseller, 'owner')->create(), 'account')->create();
        $deleted = ServerBackup::factory()->create(['user_id' => null, 'owner_id' => $this->reseller->id, 'username' => 'lama', 'backup_run_id' => null]);
        ServerBackup::factory()->create();

        $this->actingAs($this->reseller)
            ->get(route('reseller.backups.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('reseller/backups/index')
                ->where('scope', 'reseller')
                ->has('backups.data', 2)
                ->where('backups.data.0.id', $deleted->id)
                ->where('backups.data.0.can_delete', true)
                ->where('backups.data.1.id', $mine->id)
                ->where('backups.data.1.can_delete', false)
                ->missing('schedule')
                ->missing('space'));

        // The administrator's automatic backups stay.
        $this->actingAs($this->reseller)
            ->delete(route('reseller.backups.destroy', $mine))
            ->assertForbidden();

        $this->actingAs($this->reseller)->delete(route('reseller.backups.destroy', $deleted))->assertSessionHasNoErrors();
        $this->assertModelMissing($deleted);
        $this->assertModelExists($mine);
    }

    public function test_a_deleted_account_is_made_again_with_its_websites_then_the_rest_once_it_exists()
    {
        $package = Package::factory()->for($this->reseller, 'owner')->create(['name' => 'Bisnis']);
        Domain::factory()->create(['name' => 'taken.example']);
        $backup = ServerBackup::factory()->create(['user_id' => null, 'owner_id' => $this->reseller->id, 'username' => 'lama', 'size_bytes' => 5000]);
        $this->answers['backup.server_inspect'] = $this->meta($this->settings(Hash::make('Old-Password-123')));

        $this->actingAs($this->admin)
            ->get(route('admin.backups.index', ['restore' => $backup->id]))
            ->assertInertia(fn (Assert $page) => $page->reloadOnly('inspected', fn (Assert $reload) => $reload
                ->where('inspected.account.email', 'lama@example.com')
                ->where('inspected.account.has_password', true)
                ->where('inspected.account.owner', 'jual')
                ->where('inspected.account.package_id', $package->id)
                ->missing('inspected.settings')));

        $this->actingAs($this->admin)
            ->post(route('admin.backups.restore-account', $backup), ['package_id' => $package->id])
            ->assertSessionHasNoErrors();

        $account = User::query()->where('username', 'lama')->sole();
        $this->assertSame([AccountStatus::Pending, $this->reseller->id, $package->id, 'Pak Lama', 'lama@example.com'], [$account->status, $account->owner_id, $account->package_id, $account->name, $account->email]);
        $this->assertTrue(Hash::check('Old-Password-123', $account->password));

        $domains = $account->domains()->orderBy('name')->get();
        $this->assertSame(['blog.lama.example', 'lama.example', 'shop.example', 'www-lama.example'], $domains->pluck('name')->all());
        $this->assertSame([DomainType::Subdomain, DomainType::Domain, DomainType::Domain, DomainType::Pointer], $domains->pluck('type')->all());
        $this->assertTrue($domains->firstWhere('name', 'lama.example')->is_primary);
        $this->assertSame('8.2', $domains->firstWhere('name', 'shop.example')->php_version);

        $restore = AccountRestore::query()->sole();
        $this->assertSame(AccountRestore::STATUS_WAITING, $restore->status);
        $this->assertCount(1, $restore->skipped);
        $this->assertStringStartsWith('taken.example: ', $restore->skipped[0]);
        $this->assertSame(['account.create'], Task::query()->pluck('action')->all());
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'backup.account_restored', 'actor_id' => $this->admin->id]);

        // Once the account exists on the server, the rest comes back.
        $created = Task::query()->sole();
        $created->markSucceeded(['uid' => 1500, 'gid' => 1500]);
        event(new AgentTaskFinished($created));

        $this->assertSame(AccountStatus::Active, $account->fresh()->status);
        $this->assertSame(AccountRestore::STATUS_RESTORING, $restore->fresh()->status);
        $this->assertTrue(Database::query()->where('user_id', $account->id)->where('name', 'lama_shop')->exists());
        $this->assertSame(['lama_wp'], $account->databaseUsers()->pluck('name')->all());
        $this->assertSame(['info'], $account->domains()->where('name', 'lama.example')->sole()->mailDomain->mailboxes()->pluck('local_part')->all());
        $this->assertSame(1, $account->cronJobs()->where('enabled', false)->count());
        $this->assertSame(['billing', 'sales'], $account->domains()->where('name', 'lama.example')->sole()->mailDomain->forwarders()->orderBy('local_part')->pluck('local_part')->all());
        $this->assertSame('owner@example.net,boss@example.net', $account->domains()->where('name', 'lama.example')->sole()->mailDomain->forwarders()->where('local_part', 'billing')->value('destinations'));
        $this->assertStringStartsWith('broken@lama.example: ', $restore->fresh()->skipped[1]);

        $restoreTask = Task::query()->where('action', 'backup.server_restore')->sole();
        $this->assertSame(['username' => 'lama', 'name' => $backup->name, 'files' => true, 'email' => true, 'databases' => ['lama_shop']], $restoreTask->payload);
    }

    public function test_a_deleted_account_is_only_made_again_when_its_username_is_free_and_it_has_a_password()
    {
        $backup = ServerBackup::factory()->create(['user_id' => null, 'owner_id' => $this->admin->id, 'username' => 'lama']);
        $this->answers['backup.server_inspect'] = $this->meta($this->settings(null));

        $this->actingAs($this->admin)
            ->post(route('admin.backups.restore-account', $backup))
            ->assertSessionHasErrors(['password' => 'The backup does not hold the account\'s password; enter a new one.']);

        User::factory()->create(['username' => 'lama']);

        $this->actingAs($this->admin)
            ->post(route('admin.backups.restore-account', $backup), ['password' => 'A-New-Password-123'])
            ->assertSessionHasErrors(['backup' => 'The username lama is in use again, so the account cannot be made again.']);

        $existing = ServerBackup::factory()->for(User::factory()->create(), 'account')->create();

        $this->actingAs($this->admin)
            ->post(route('admin.backups.restore-account', $existing), ['password' => 'A-New-Password-123'])
            ->assertSessionHasErrors(['backup' => 'The account of this backup still exists; restore the backup into it instead.']);

        $this->assertSame(0, AccountRestore::query()->count());
        $this->assertSame(0, Task::query()->count());
    }

    public function test_a_restore_the_account_could_not_be_created_for_fails_and_tells_the_admins()
    {
        NotificationChannel::factory()->for($this->admin)->create();
        $restore = AccountRestore::factory()->create();
        $task = Task::create(['user_id' => $restore->user_id, 'action' => 'account.create', 'payload' => [], 'status' => TaskStatus::Running]);
        $task->markFailed('useradd: user exists');

        event(new AgentTaskFinished($task));

        $this->assertSame([AccountRestore::STATUS_FAILED, 'The account could not be created on the server.'], [$restore->fresh()->status, $restore->fresh()->error]);
        Queue::assertPushed(DeliverAlert::class, fn (DeliverAlert $job) => str_contains($job->subject, 'could not be restored'));
    }

    public function test_a_restore_waits_for_the_tasks_queued_before_it_for_the_account()
    {
        $account = User::factory()->create(['username' => 'lama']);
        $earlier = Task::create(['user_id' => $account->id, 'action' => 'db.create', 'payload' => [], 'status' => TaskStatus::Queued]);
        $restore = Task::create(['user_id' => $account->id, 'action' => 'backup.server_restore', 'payload' => ['username' => 'lama'], 'status' => TaskStatus::Queued]);

        $job = (new RunAgentTask($restore))->withFakeQueueInteractions();
        $job->handle(app(AgentClient::class));

        $job->assertReleased(10);
        $this->assertSame(TaskStatus::Queued, $restore->fresh()->status);

        $earlier->markSucceeded([]);
        $job = (new RunAgentTask($restore))->withFakeQueueInteractions();
        $job->handle(app(AgentClient::class));

        $job->assertNotReleased();
        $this->assertSame(TaskStatus::Success, $restore->fresh()->status);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{manifest: string, settings: string}
     */
    private function meta(array $settings): array
    {
        return [
            'manifest' => (string) json_encode(['format' => 1, 'username' => 'lama', 'created_at' => '2026-11-13T02:00:00+00:00', 'files' => true, 'email' => true, 'databases' => ['lama_shop']]),
            'settings' => (string) json_encode(['format' => 1, ...$settings]),
        ];
    }

    /**
     * The settings of a backup of the deleted account lama.
     *
     * @return array<string, mixed>
     */
    private function settings(?string $passwordHash): array
    {
        return [
            'account' => ['name' => 'Pak Lama', 'email' => 'lama@example.com', 'password_hash' => $passwordHash, 'package' => 'Bisnis', 'owner' => 'jual'],
            'domains' => [
                ['name' => 'lama.example', 'type' => 'domain', 'parent' => null, 'php_version' => '8.4', 'is_primary' => true],
                ['name' => 'shop.example', 'type' => 'domain', 'parent' => null, 'php_version' => '8.2', 'is_primary' => false],
                ['name' => 'taken.example', 'type' => 'domain', 'parent' => null, 'php_version' => '8.4', 'is_primary' => false],
                ['name' => 'www-lama.example', 'type' => 'pointer', 'parent' => 'lama.example', 'pointer_mode' => 'alias', 'php_version' => '8.4', 'is_primary' => false],
                ['name' => 'blog.lama.example', 'type' => 'subdomain', 'parent' => 'lama.example', 'php_version' => '8.4', 'is_primary' => false],
            ],
            'databases' => ['lama_shop'],
            'database_users' => [['name' => 'lama_wp', 'password_hash' => '*'.str_repeat('A', 40), 'databases' => ['lama_shop']]],
            'mailboxes' => [['domain' => 'lama.example', 'local_part' => 'info', 'password_hash' => '$6$abcdefgh$'.str_repeat('a', 86), 'quota_mb' => null]],
            'forwarders' => [
                ['domain' => 'lama.example', 'local_part' => 'sales', 'destinations' => ['owner@example.net']],
                // As backups made before the list kept them.
                ['domain' => 'lama.example', 'local_part' => 'billing', 'destinations' => 'owner@example.net,boss@example.net'],
                ['domain' => 'lama.example', 'local_part' => 'broken', 'destinations' => ['not an address']],
            ],
            'cron_jobs' => [['schedule' => '*/5 * * * *', 'command' => 'php artisan schedule:run', 'description' => null, 'enabled' => false]],
        ];
    }
}
