<?php

namespace Tests\Feature\Backups;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Enums\AccountStatus;
use App\Enums\ServerBackupStatus;
use App\Enums\TaskStatus;
use App\Events\AgentTaskFinished;
use App\Jobs\CreateSystemBackup;
use App\Jobs\DeliverAlert;
use App\Jobs\RunAgentTask;
use App\Models\AuditLog;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\NotificationChannel;
use App\Models\ServerBackup;
use App\Models\SystemBackup;
use App\Models\Task;
use App\Models\User;
use App\Services\FileManagerService;
use App\Services\ServerBackupService;
use App\Services\SystemBackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Backups of the panel itself, and the panel restored from one on a new
 * server.
 */
class SystemBackupTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

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
        config(['app.url' => 'https://panel.example.com:2222']);

        $this->admin = User::factory()->admin()->create();

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

    public function test_the_panel_is_backed_up_once_a_day_on_the_backups_queue()
    {
        $this->artisan('rayxo:run-backups')->assertSuccessful();

        $backup = SystemBackup::query()->sole();
        $this->assertSame(['rayxo-system-panel.example.com-2026-11-14-100000.tar.gz', ServerBackupStatus::Queued], [$backup->name, $backup->status]);
        Queue::assertPushedOn(ServerBackupService::QUEUE, CreateSystemBackup::class);

        // Not again while it is going, nor within the day once it is done.
        $this->travel(1)->hour();
        $this->artisan('rayxo:run-backups')->assertSuccessful();
        $backup->forceFill(['status' => ServerBackupStatus::Completed])->save();
        $this->travel(5)->hours();
        $this->artisan('rayxo:run-backups')->assertSuccessful();
        $this->assertSame(1, SystemBackup::query()->count());

        $this->travel(1)->day();
        $this->artisan('rayxo:run-backups')->assertSuccessful();
        $this->assertSame(2, SystemBackup::query()->count());

        // Turned off, none is made.
        $this->actingAs($this->admin)->put(route('admin.backups.system.settings'), ['enabled' => false, 'keep' => 7])->assertSessionHasNoErrors();
        SystemBackup::query()->update(['status' => ServerBackupStatus::Completed]);
        $this->travel(2)->days();
        $this->artisan('rayxo:run-backups')->assertSuccessful();
        $this->assertSame(2, SystemBackup::query()->count());
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'backup.system_settings_changed', 'actor_id' => $this->admin->id]);
    }

    public function test_a_backup_of_the_panel_is_made_by_the_agent_and_the_oldest_rotated_out()
    {
        $this->actingAs($this->admin)->put(route('admin.backups.system.settings'), ['enabled' => true, 'keep' => 2]);
        $older = SystemBackup::factory()->count(2)->create();
        $backup = SystemBackup::factory()->create(['status' => ServerBackupStatus::Queued, 'size_bytes' => null]);
        User::factory()->count(2)->create();
        $this->answers['backup.system_create'] = ['name' => $backup->name, 'size' => 4096];

        app(SystemBackupService::class)->create($backup);

        [, $params] = collect($this->agentCalls)->firstWhere(0, 'backup.system_create');
        $this->assertSame([$backup->name, 'RAYXO', 'panel.example.com', 2], [$params['name'], $params['manifest']['panel'], $params['manifest']['hostname'], $params['manifest']['accounts']]);
        $this->assertSame([ServerBackupStatus::Completed, 4096], [$backup->fresh()->status, $backup->fresh()->size_bytes]);
        $this->assertSame([['names' => [$older[0]->name]]], collect($this->agentCalls)->where(0, 'backup.system_delete')->pluck(1)->values()->all());
        $this->assertModelMissing($older[0]);
        $this->assertModelExists($older[1]);
    }

    public function test_a_failed_backup_of_the_panel_tells_the_admins()
    {
        NotificationChannel::factory()->for($this->admin)->create();
        $backup = SystemBackup::factory()->create(['status' => ServerBackupStatus::Queued]);
        $this->answers['backup.system_create'] = new AgentException('the disk of the backups is full', reason: 'no_space');

        app(SystemBackupService::class)->create($backup);

        $this->assertSame([ServerBackupStatus::Failed, 'The server disk does not have enough free space for backups.'], [$backup->fresh()->status, $backup->fresh()->error]);
        Queue::assertPushed(DeliverAlert::class, fn (DeliverAlert $job) => $job->subject === 'The backup of the panel failed');
    }

    public function test_the_admin_backs_up_downloads_and_deletes_the_panels_backups()
    {
        $this->actingAs($this->admin)->post(route('admin.backups.system.store'))->assertSessionHasNoErrors();
        $this->assertSame(ServerBackupStatus::Queued, SystemBackup::query()->sole()->status);

        $this->actingAs($this->admin)
            ->post(route('admin.backups.system.store'))
            ->assertSessionHasErrors(['system' => 'A backup of the panel is already being made.']);

        $backup = SystemBackup::factory()->create();
        $transfer = null;
        $this->answers['backup.system_download'] = function (array $params) use (&$transfer): array {
            $transfer = FileManagerService::transferPath($params['transfer'].'.download');
            File::ensureDirectoryExists(FileManagerService::transferDir());
            File::put($transfer, 'the panel');

            return [];
        };

        $response = $this->actingAs($this->admin)->get(route('admin.backups.system.download', $backup));
        $response->assertOk()->assertDownload($backup->name);
        $this->assertSame('the panel', $response->streamedContent());
        $this->assertFileDoesNotExist($transfer);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'backup.system_downloaded', 'actor_id' => $this->admin->id]);

        $this->actingAs($this->admin)->delete(route('admin.backups.system.destroy', $backup))->assertSessionHasNoErrors();
        $this->assertModelMissing($backup);
        $this->assertSame([['names' => [$backup->name]]], collect($this->agentCalls)->where(0, 'backup.system_delete')->pluck(1)->values()->all());

        $reseller = User::factory()->reseller()->create();
        $this->actingAs($reseller)->post(route('admin.backups.system.store'))->assertForbidden();
    }

    public function test_a_restored_panel_makes_its_accounts_and_databases_again_and_drops_what_was_going()
    {
        $active = User::factory()->create(['username' => 'aktif']);
        $suspended = User::factory()->create(['username' => 'diam', 'status' => AccountStatus::Suspended]);
        $deleting = User::factory()->create(['username' => 'pergi', 'status' => AccountStatus::Deleting]);
        $database = Database::factory()->for($active, 'account')->create(['name' => 'aktif_shop']);
        DatabaseUser::factory()->for($active, 'account')->create(['name' => 'aktif_wp'])->databases()->attach($database);
        $stale = Task::create(['user_id' => $active->id, 'action' => 'web.apply_domain', 'payload' => [], 'status' => TaskStatus::Queued]);
        $running = ServerBackup::factory()->for($active, 'account')->create(['status' => ServerBackupStatus::Running]);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => 0, 'created_at' => 0]);
        $itself = SystemBackup::factory()->create(['status' => ServerBackupStatus::Running]);
        $other = SystemBackup::factory()->create(['status' => ServerBackupStatus::Queued]);

        $this->artisan('rayxo:restore-server', ['--system-backup' => $itself->name])->assertSuccessful();

        $this->assertSame(ServerBackupStatus::Completed, $itself->fresh()->status);
        $this->assertSame(ServerBackupStatus::Failed, $other->fresh()->status);

        $this->assertSame(TaskStatus::Failed, $stale->fresh()->status);
        $this->assertSame(ServerBackupStatus::Failed, $running->fresh()->status);
        $this->assertSame(0, DB::table('jobs')->count());

        $queued = Task::query()->where('status', TaskStatus::Queued)->orderBy('id')->get();
        $actions = $queued->map(fn (Task $task): string => $task->action.' '.($task->payload['username'] ?? ''))->all();

        $this->assertSame(['account.create aktif', 'account.create diam', 'account.delete pergi'], array_slice($actions, 0, 3));
        $this->assertContains('db.create_database aktif', $actions);
        $this->assertContains('db.apply_user aktif', $actions);
        $this->assertSame('account.suspend diam', end($actions));
        $this->assertGreaterThan(5, count($actions));
        $this->assertSame([$active->id, $suspended->id, $deleting->id], $queued->take(3)->pluck('user_id')->all());
    }

    public function test_an_account_made_again_takes_the_ids_the_new_server_gave_it()
    {
        $account = User::factory()->create(['username' => 'aktif', 'uid' => 1001, 'gid' => 1001]);
        $task = Task::create(['user_id' => $account->id, 'action' => 'account.create', 'payload' => ['username' => 'aktif'], 'status' => TaskStatus::Running]);
        $task->markSucceeded(['uid' => 1007, 'gid' => 1008]);

        event(new AgentTaskFinished($task));

        $account->refresh();
        $this->assertSame([AccountStatus::Active, 1007, 1008], [$account->status, $account->uid, $account->gid]);
        $this->assertSame(1, Task::query()->count());
    }

    public function test_every_account_is_restored_from_its_newest_backup_on_this_server()
    {
        $dir = storage_path('framework/testing/backups-'.uniqid());
        config(['hosting.backups.dir' => $dir]);
        File::ensureDirectoryExists($dir);

        $budi = User::factory()->create(['username' => 'budi']);
        ServerBackup::factory()->for($budi, 'account')->create();
        $newest = ServerBackup::factory()->for($budi, 'account')->create(['databases' => ['budi_shop']]);
        File::put($newest->path(), 'backup');
        User::factory()->create(['username' => 'sari']);
        $this->answers['backup.server_inspect'] = [
            'manifest' => (string) json_encode(['format' => 1, 'username' => 'budi', 'files' => true, 'email' => true, 'databases' => ['budi_shop']]),
            'settings' => (string) json_encode(['format' => 1, 'database_users' => [], 'mailboxes' => []]),
        ];

        try {
            $this->actingAs($this->admin)
                ->post(route('admin.backups.restore-all'))
                ->assertSessionHas('inertia.flash_data.toast.message', 'Restoring 1 account(s). See Tasks for how it goes. Not restored: sari: no backup of it is on this server');
        } finally {
            File::deleteDirectory($dir);
        }

        $task = Task::query()->where('action', 'backup.server_restore')->sole();
        $this->assertSame(['username' => 'budi', 'name' => $newest->name, 'files' => true, 'email' => true, 'databases' => ['budi_shop']], $task->payload);
        Queue::assertPushed(RunAgentTask::class, fn (RunAgentTask $job) => $job->task->is($task) && $job->queue === ServerBackupService::QUEUE);
    }
}
