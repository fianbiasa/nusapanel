<?php

namespace Tests\Feature\Backups;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Enums\AccountStatus;
use App\Enums\BackupRunStatus;
use App\Enums\ServerBackupStatus;
use App\Jobs\CreateServerBackup;
use App\Jobs\DeliverAlert;
use App\Jobs\FinishBackupRun;
use App\Jobs\RunAgentTask;
use App\Models\AuditLog;
use App\Models\BackupRun;
use App\Models\Database;
use App\Models\NotificationChannel;
use App\Models\ServerBackup;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use App\Services\ServerBackupService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\TestCase;

class ServerBackupTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $budi;

    /**
     * @var list<array{0: string, 1: array<string, mixed>}>
     */
    private array $agentCalls = [];

    /**
     * What the agent answers, by method: an answer, a function of the
     * params, or an exception it throws.
     *
     * @var array<string, mixed>
     */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->travelTo('2026-11-14 10:00:00');

        $this->admin = User::factory()->admin()->create();
        $this->budi = User::factory()->create(['username' => 'budi']);

        $this->answers = [
            'system.settings' => ['timezone' => 'UTC'],
            'backup.server_space' => ['total' => 100 * 1024 ** 3, 'free' => 60 * 1024 ** 3, 'reserve' => 5 * 1024 ** 3],
        ];

        $this->mock(AgentClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('call')->andReturnUsing(function (string $method, array $params = []): array {
                $this->agentCalls[] = [$method, $params];
                $answer = $this->answers[$method] ?? [];

                if ($answer instanceof AgentException) {
                    throw $answer;
                }

                return is_callable($answer) ? $answer($params) : $answer;
            });
        });
    }

    public function test_the_admin_turns_the_automatic_backups_on_with_a_schedule_and_a_rotation()
    {
        $this->actingAs($this->admin)
            ->put(route('admin.backups.schedule'), $this->schedule(['frequency' => 'weekly', 'weekday' => 6, 'hour' => 3, 'keep' => 7, 'email' => false]))
            ->assertSessionHasNoErrors();

        $saved = app(ServerBackupService::class)->schedule();
        $this->assertSame(['enabled' => true, 'frequency' => 'weekly', 'hour' => 3, 'weekday' => 6, 'day' => 1, 'keep' => 7, 'files' => true, 'email' => false, 'databases' => true], array_diff_key($saved, ['enabled_at' => 0]));
        $this->assertSame(now()->toIso8601String(), $saved['enabled_at']);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'backup.schedule_changed', 'actor_id' => $this->admin->id]);

        // Changing only the rotation keeps when the schedule started.
        $this->travel(1)->hours();

        $this->actingAs($this->admin)->put(route('admin.backups.schedule'), $this->schedule(['frequency' => 'weekly', 'weekday' => 6, 'hour' => 3, 'keep' => 2, 'email' => false]));

        $this->assertSame($saved['enabled_at'], app(ServerBackupService::class)->schedule()['enabled_at']);
    }

    public function test_a_schedule_must_be_sensible_and_only_the_admin_sets_it()
    {
        $this->actingAs($this->admin)
            ->put(route('admin.backups.schedule'), $this->schedule(['frequency' => 'hourly', 'keep' => 31, 'day' => 31]))
            ->assertSessionHasErrors(['frequency', 'keep', 'day']);

        $this->actingAs($this->admin)
            ->put(route('admin.backups.schedule'), $this->schedule(['files' => false, 'email' => false, 'databases' => false]))
            ->assertSessionHasErrors(['items' => 'Choose what the backups hold.']);

        $this->actingAs($this->budi)->put(route('admin.backups.schedule'), $this->schedule())->assertForbidden();
        $this->actingAs($this->budi)->get(route('admin.backups.index'))->assertForbidden();

        $this->assertDatabaseMissing(Setting::class, ['key' => ServerBackupService::SETTING]);
    }

    public function test_a_run_is_due_at_the_hour_of_the_servers_timezone_but_not_for_a_time_that_passed_before_it_was_turned_on()
    {
        $this->answers['system.settings'] = ['timezone' => 'Asia/Jakarta'];
        $backups = app(ServerBackupService::class);

        // 10:00 UTC is 17:00 in Jakarta: 02:00 today already passed.
        $this->actingAs($this->admin)->put(route('admin.backups.schedule'), $this->schedule(['hour' => 2]));
        $this->assertFalse($backups->due(CarbonImmutable::now()));

        // 02:00 in Jakarta is 19:00 UTC the day before.
        $this->travelTo('2026-11-14 18:59:00');
        $this->assertFalse($backups->due(CarbonImmutable::now()));

        $this->travelTo('2026-11-14 19:00:00');
        $this->assertTrue($backups->due(CarbonImmutable::now()));

        BackupRun::factory()->create(['started_at' => now()]);
        $this->assertFalse($backups->due(CarbonImmutable::now()));

        // A run missed while the server was down starts once it is up.
        $this->travelTo('2026-11-16 01:00:00');
        $this->assertTrue($backups->due(CarbonImmutable::now()));
    }

    public function test_weekly_and_monthly_schedules_name_their_day()
    {
        $backups = app(ServerBackupService::class);
        $now = CarbonImmutable::parse('2026-11-14 10:00:00'); // A Saturday.

        $this->assertSame('2026-11-09 02:00', $backups->lastScheduledAt(['frequency' => 'weekly', 'hour' => 2, 'weekday' => 1, 'day' => 1], $now)->format('Y-m-d H:i'));
        $this->assertSame('2026-11-07 23:00', $backups->lastScheduledAt(['frequency' => 'weekly', 'hour' => 23, 'weekday' => 6, 'day' => 1], $now)->format('Y-m-d H:i'));
        $this->assertSame('2026-10-28 02:00', $backups->lastScheduledAt(['frequency' => 'monthly', 'hour' => 2, 'weekday' => 0, 'day' => 28], $now)->format('Y-m-d H:i'));
        $this->assertSame('2026-11-01 02:00', $backups->lastScheduledAt(['frequency' => 'monthly', 'hour' => 2, 'weekday' => 0, 'day' => 1], $now)->format('Y-m-d H:i'));
        $this->assertSame('2026-12-01 02:00', $backups->nextScheduledAt(['frequency' => 'monthly', 'hour' => 2, 'weekday' => 0, 'day' => 1], $now)->format('Y-m-d H:i'));
    }

    public function test_the_scheduler_backs_up_every_hosting_account_one_job_each_on_the_backups_queue()
    {
        $this->actingAs($this->admin)->put(route('admin.backups.schedule'), $this->schedule());
        $suspended = User::factory()->create(['username' => 'sari', 'status' => AccountStatus::Suspended]);
        User::factory()->create(['status' => AccountStatus::Deleting]);
        User::factory()->reseller()->create();

        $this->travelTo('2026-11-15 02:00:00');
        $this->artisan('rayxo:run-backups')->assertSuccessful();

        $run = BackupRun::query()->sole();
        $this->assertSame([BackupRun::TRIGGER_SCHEDULE, BackupRunStatus::Running, 2], [$run->trigger, $run->status, $run->accounts]);
        $this->assertSame(['budi-2026-11-15-020000.tar.gz', 'sari-2026-11-15-020000.tar.gz'], ServerBackup::query()->orderBy('name')->pluck('name')->all());
        $this->assertSame([$this->budi->id, $suspended->id], ServerBackup::query()->orderBy('name')->pluck('user_id')->all());

        Queue::assertPushedOn(ServerBackupService::QUEUE, CreateServerBackup::class);
        Queue::assertPushed(CreateServerBackup::class, 2);
        Queue::assertPushedOn(ServerBackupService::QUEUE, FinishBackupRun::class);

        // Within the same period nothing starts again.
        $this->travelTo('2026-11-15 03:00:00');
        $this->artisan('rayxo:run-backups')->assertSuccessful();
        $this->assertSame(1, BackupRun::query()->count());
    }

    public function test_a_run_is_skipped_and_the_admins_told_when_the_disk_has_no_room_for_it()
    {
        NotificationChannel::factory()->for($this->admin)->create();
        $this->budi->diskUsage()->create(['disk_kb' => 50 * 1024 * 1024, 'inodes' => 10, 'measured_at' => now()]);
        Database::factory()->for($this->budi, 'account')->create(['size_bytes' => 6 * 1024 ** 3]);

        $this->artisan('rayxo:run-backups --now')->assertSuccessful();

        $run = BackupRun::query()->sole();
        $this->assertSame(BackupRunStatus::Skipped, $run->status);
        $this->assertStringContainsString('need about 56.0 GB, but the disk has 60.0 GB free and backups always leave 5.0 GB', (string) $run->message);
        $this->assertSame(0, ServerBackup::query()->count());
        Queue::assertNotPushed(CreateServerBackup::class);
        Queue::assertPushed(DeliverAlert::class, fn (DeliverAlert $job) => $job->subject === 'Automatic backups were skipped');
    }

    public function test_a_backup_of_an_account_is_made_by_the_agent_with_its_dumps_and_settings()
    {
        Database::factory()->for($this->budi, 'account')->create(['name' => 'budi_shop']);
        $backup = $this->queued();
        $this->answers['backup.server_create'] = ['name' => $backup->name, 'size' => 123456, 'skipped' => 2];

        app(ServerBackupService::class)->create($backup, true);

        [$method, $params] = collect($this->agentCalls)->firstWhere(0, 'backup.server_create');
        $this->assertSame('backup.server_create', $method);
        $this->assertSame(['budi', $backup->name, true, true, ['budi_shop']], [$params['username'], $params['name'], $params['files'], $params['email'], $params['databases']]);
        $this->assertSame(['budi', ['budi_shop']], [$params['manifest']['username'], $params['settings']['databases']]);

        $backup->refresh();
        $this->assertSame([ServerBackupStatus::Completed, 123456, 2, ['budi_shop']], [$backup->status, $backup->size_bytes, $backup->skipped, $backup->databases]);
    }

    public function test_a_failed_backup_says_why_and_one_of_a_deleted_account_is_not_made()
    {
        $backup = $this->queued();
        $this->answers['backup.server_create'] = new AgentException('the disk of the backups has 1.0 GB free', reason: 'no_space');

        app(ServerBackupService::class)->create($backup, false);

        $this->assertSame([ServerBackupStatus::Failed, 'The server disk does not have enough free space for backups.'], [$backup->fresh()->status, $backup->fresh()->error]);

        $gone = $this->queued(User::factory()->create(['status' => AccountStatus::Deleting]));
        app(ServerBackupService::class)->create($gone, false);

        $this->assertSame('The account no longer exists.', $gone->fresh()->error);
        $this->assertSame(1, collect($this->agentCalls)->where(0, 'backup.server_create')->count());
    }

    public function test_finishing_a_run_keeps_the_newest_backups_of_each_account_and_reports_failures()
    {
        NotificationChannel::factory()->for($this->admin)->create();
        $this->actingAs($this->admin)->put(route('admin.backups.schedule'), $this->schedule(['keep' => 2]));

        $old = ServerBackup::factory()->for($this->budi, 'account')->count(2)->create();
        $manual = ServerBackup::factory()->for($this->budi, 'account')->create(['backup_run_id' => null]);
        $deleted = ServerBackup::factory()->create(['user_id' => null, 'username' => 'gone']);
        $run = BackupRun::factory()->running()->create(['accounts' => 2]);
        $newest = ServerBackup::factory()->for($run, 'run')->for($this->budi, 'account')->create();
        ServerBackup::factory()->for($run, 'run')->create(['status' => ServerBackupStatus::Running, 'size_bytes' => null]);

        app(ServerBackupService::class)->finish($run);

        $run->refresh();
        $this->assertSame([BackupRunStatus::Completed, 1, 1], [$run->status, $run->succeeded, $run->failed]);
        $this->assertSame([['names' => [$old[0]->name]]], collect($this->agentCalls)->where(0, 'backup.server_delete')->pluck(1)->values()->all());
        $this->assertModelMissing($old[0]);

        foreach ([$old[1], $manual, $deleted, $newest] as $kept) {
            $this->assertModelExists($kept);
        }

        Queue::assertPushed(DeliverAlert::class, fn (DeliverAlert $job) => $job->subject === 'Automatic backup of 1 account(s) failed'
            && str_contains($job->body, 'The backup did not finish.'));
    }

    public function test_a_run_that_stopped_moving_is_closed_so_the_next_can_start()
    {
        $run = BackupRun::factory()->running()->create(['started_at' => now()->subHours(9)]);
        ServerBackup::factory()->for($run, 'run')->for($this->budi, 'account')->create(['status' => ServerBackupStatus::Queued, 'updated_at' => now()->subHours(8)]);

        $this->artisan('rayxo:run-backups')->assertSuccessful();

        $this->assertSame([BackupRunStatus::Completed, 1], [$run->fresh()->status, $run->fresh()->failed]);
    }

    public function test_the_page_shows_the_schedule_the_disk_the_estimate_and_the_backups()
    {
        $this->budi->diskUsage()->create(['disk_kb' => 1024 * 1024, 'inodes' => 10, 'measured_at' => now()]);
        $sari = User::factory()->create(['username' => 'sari']);
        ServerBackup::factory()->for($sari, 'account')->create(['size_bytes' => 300]);
        ServerBackup::factory()->create(['user_id' => null, 'username' => 'gone', 'size_bytes' => 500]);

        $this->actingAs($this->admin)
            ->get(route('admin.backups.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/backups/index')
                ->where('schedule.enabled', false)
                ->where('timezone', 'UTC')
                ->where('next_run', null)
                ->where('space.reserve', 5 * 1024 ** 3)
                ->where('estimate', ['accounts' => 2, 'per_run' => 1024 ** 3 + 300, 'stored' => 800])
                ->where('running', false)
                ->has('backups.data', 2)
                ->where('backups.data.0.username', 'gone')
                ->where('backups.data.0.account_exists', false)
                ->where('backups.data.1.account_exists', true)
                ->missing('inspected'));

        $this->actingAs($this->admin)
            ->get(route('admin.backups.index', ['search' => 'sar']))
            ->assertInertia(fn (Assert $page) => $page->has('backups.data', 1)->where('backups.data.0.username', 'sari'));
    }

    public function test_the_admin_runs_the_backups_by_hand_but_not_twice_at_once()
    {
        $this->actingAs($this->admin)->post(route('admin.backups.run'))->assertSessionHasNoErrors();

        $this->assertSame(BackupRun::TRIGGER_MANUAL, BackupRun::query()->sole()->trigger);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'backup.run_started', 'actor_id' => $this->admin->id]);

        $this->actingAs($this->admin)
            ->post(route('admin.backups.run'))
            ->assertSessionHasErrors(['backup' => 'A backup run is still going.']);
    }

    public function test_a_backup_is_restored_into_its_account_as_a_task_on_the_backups_queue()
    {
        $backup = ServerBackup::factory()->for($this->budi, 'account')->create();
        $this->answers['backup.server_inspect'] = [
            'manifest' => (string) json_encode(['format' => 1, 'username' => 'budi', 'created_at' => '2026-11-14T02:00:00+00:00', 'files' => true, 'email' => true, 'databases' => ['budi_shop']]),
            'settings' => (string) json_encode(['format' => 1, 'database_users' => [], 'mailboxes' => []]),
        ];

        $this->actingAs($this->admin)
            ->get(route('admin.backups.index', ['restore' => $backup->id]))
            ->assertInertia(fn (Assert $page) => $page->reloadOnly('inspected', fn (Assert $reload) => $reload
                ->where('inspected.databases', ['budi_shop'])
                ->missing('inspected.settings')));

        $this->actingAs($this->admin)
            ->post(route('admin.backups.restore', $backup), ['files' => true, 'email' => false, 'databases' => ['budi_shop']])
            ->assertSessionHasNoErrors();

        $task = Task::query()->where('action', 'backup.server_restore')->sole();
        $this->assertSame($this->budi->id, $task->user_id);
        $this->assertSame(['username' => 'budi', 'name' => $backup->name, 'files' => true, 'email' => false, 'databases' => ['budi_shop']], $task->payload);
        $this->assertDatabaseHas(Database::class, ['user_id' => $this->budi->id, 'name' => 'budi_shop']);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'backup.server_restored', 'actor_id' => $this->admin->id]);

        // Only the restore waits on the backups queue; the database it brings
        // back is made on the panel's own.
        $queues = Queue::pushed(RunAgentTask::class)->mapWithKeys(fn (RunAgentTask $job) => [$job->task->action => $job->queue]);
        $this->assertSame(ServerBackupService::QUEUE, $queues['backup.server_restore']);
        $this->assertCount(1, $queues->filter());
    }

    public function test_only_a_finished_backup_of_an_existing_account_is_restored()
    {
        $deleted = ServerBackup::factory()->create(['user_id' => null, 'username' => 'gone']);
        $failed = ServerBackup::factory()->for($this->budi, 'account')->create(['status' => ServerBackupStatus::Failed]);

        $this->actingAs($this->admin)
            ->post(route('admin.backups.restore', $deleted), ['files' => true])
            ->assertSessionHasErrors(['backup' => 'The account of this backup no longer exists; restore the account from it instead.']);

        $this->actingAs($this->admin)
            ->post(route('admin.backups.restore', $failed), ['files' => true])
            ->assertSessionHasErrors(['backup' => 'Only a finished backup can be restored.']);

        $this->assertSame(0, Task::query()->count());
    }

    public function test_the_admin_downloads_and_deletes_a_backup()
    {
        $dir = storage_path('framework/testing/backups-'.uniqid());
        config(['hosting.backups.dir' => $dir]);
        $backup = ServerBackup::factory()->for($this->budi, 'account')->create();
        File::ensureDirectoryExists($dir);
        File::put($backup->path(), 'backup');

        try {
            $this->actingAs($this->admin)
                ->get(route('admin.backups.download', $backup))
                ->assertOk()
                ->assertDownload($backup->name);
        } finally {
            File::deleteDirectory($dir);
        }

        $this->assertDatabaseHas(AuditLog::class, ['action' => 'backup.server_downloaded', 'actor_id' => $this->admin->id]);
        $this->actingAs($this->admin)->get(route('admin.backups.download', $backup))->assertNotFound();

        $this->actingAs($this->admin)->delete(route('admin.backups.destroy', $backup))->assertSessionHasNoErrors();

        $this->assertModelMissing($backup);
        $this->assertSame([['names' => [$backup->name]]], collect($this->agentCalls)->where(0, 'backup.server_delete')->pluck(1)->values()->all());
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'backup.server_deleted', 'actor_id' => $this->admin->id]);

        $running = ServerBackup::factory()->for($this->budi, 'account')->create(['status' => ServerBackupStatus::Running]);
        $this->actingAs($this->admin)
            ->delete(route('admin.backups.destroy', $running))
            ->assertSessionHasErrors(['backup' => 'Wait until this backup is done.']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function schedule(array $overrides = []): array
    {
        return [...ServerBackupService::DEFAULTS, 'enabled' => true, ...$overrides];
    }

    private function queued(?User $account = null): ServerBackup
    {
        $account ??= $this->budi;

        return ServerBackup::factory()->for($account, 'account')->create([
            'status' => ServerBackupStatus::Queued,
            'size_bytes' => null,
            'databases' => [],
            'started_at' => null,
            'finished_at' => null,
        ]);
    }
}
