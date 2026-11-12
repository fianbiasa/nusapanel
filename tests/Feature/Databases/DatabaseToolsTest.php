<?php

namespace Tests\Feature\Databases;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Enums\TaskStatus;
use App\Events\AgentTaskFinished;
use App\Models\AuditLog;
use App\Models\Database;
use App\Models\DatabaseRemoteHost;
use App\Models\DatabaseUser;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use App\Services\DatabaseToolsService;
use App\Services\FileManagerService;
use App\Support\DatabaseHost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DatabaseToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $account;

    private Database $database;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->account = User::factory()->create(['username' => 'budi']);
        $this->database = Database::factory()->for($this->account, 'account')->create(['name' => 'budi_shop']);
    }

    public function test_a_remote_host_is_added_in_the_form_mariadb_keeps_and_every_user_gets_it()
    {
        $wordpress = DatabaseUser::factory()->for($this->account, 'account')->create(['name' => 'budi_wp']);
        $wordpress->databases()->attach($this->database);
        DatabaseUser::factory()->for($this->account, 'account')->create(['name' => 'budi_app']);

        $this->actingAs($this->account)
            ->post(route('database-remote-hosts.store'), ['host' => ' 192.168.1.77/24 '])
            ->assertSessionHasNoErrors();

        $this->assertSame(['192.168.1.0/255.255.255.0'], $this->account->databaseRemoteHosts()->pluck('host')->all());

        $payloads = Task::query()->where('action', 'db.apply_user')->orderBy('id')->pluck('payload');
        $this->assertSame(['budi_wp', 'budi_app'], $payloads->pluck('user')->all());
        $this->assertSame(['192.168.1.0/255.255.255.0'], $payloads[0]['remote_hosts']);
        $this->assertSame(['budi_shop'], $payloads[0]['databases']);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'database.remote_host_added']);

        $this->actingAs($this->account)
            ->get(route('databases.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('remote.enabled', false)
                ->where('remote.hosts.0.host', '192.168.1.0/255.255.255.0')
                ->where('remote.max', DatabaseHost::MAX_PER_ACCOUNT));
    }

    #[DataProvider('invalidHosts')]
    public function test_hosts_that_are_not_addresses_are_refused(string $host)
    {
        $this->actingAs($this->account)
            ->post(route('database-remote-hosts.store'), ['host' => $host])
            ->assertSessionHasErrors('host');

        $this->assertDatabaseCount('database_remote_hosts', 0);
        $this->assertDatabaseCount('tasks', 0);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidHosts(): array
    {
        return [
            'a host name' => ['db.example.com'],
            'this server' => ['127.0.0.1'],
            'a wildcard in the middle' => ['192.168.%.1'],
            'a netmask with gaps' => ['10.0.0.0/255.0.255.0'],
            'sql' => ["1.2.3.4' OR 1=1"],
        ];
    }

    public function test_a_host_is_allowed_once_and_up_to_the_limit()
    {
        DatabaseRemoteHost::factory()->for($this->account, 'account')->create(['host' => '203.0.113.7']);

        $this->actingAs($this->account)
            ->post(route('database-remote-hosts.store'), ['host' => '203.0.113.7'])
            ->assertSessionHasErrors(['host' => 'This host is allowed already.']);

        DatabaseRemoteHost::factory()->for($this->account, 'account')->count(DatabaseHost::MAX_PER_ACCOUNT - 1)
            ->sequence(fn ($sequence) => ['host' => '198.51.100.'.($sequence->index + 1)])
            ->create();

        $this->actingAs($this->account)
            ->post(route('database-remote-hosts.store'), ['host' => '%'])
            ->assertSessionHasErrors('host');
    }

    public function test_a_removed_host_goes_from_every_user()
    {
        DatabaseUser::factory()->for($this->account, 'account')->create(['name' => 'budi_wp']);
        $host = DatabaseRemoteHost::factory()->for($this->account, 'account')->create(['host' => '203.0.113.7']);
        $foreign = DatabaseRemoteHost::factory()->create();

        $this->actingAs($this->account)
            ->delete(route('database-remote-hosts.destroy', $foreign))
            ->assertNotFound();

        $this->actingAs($this->account)
            ->delete(route('database-remote-hosts.destroy', $host))
            ->assertSessionHasNoErrors();

        $this->assertModelMissing($host);
        $this->assertModelExists($foreign);
        $this->assertSame([], Task::query()->sole()->payload['remote_hosts']);
    }

    public function test_a_database_is_downloaded_as_a_gzipped_dump()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('call')->once()
            ->withArgs(function (string $method, array $params): bool {
                if ($method !== 'db.export' || $params['username'] !== 'budi' || $params['database'] !== 'budi_shop') {
                    return false;
                }

                File::ensureDirectoryExists(FileManagerService::transferDir());
                File::put(FileManagerService::transferPath($params['transfer'].'.download'), 'gzipped dump');

                return true;
            })
            ->andReturn([]));

        $response = $this->actingAs($this->account)->get(route('databases.export', $this->database));

        $response->assertOk()->assertDownload();
        $this->assertMatchesRegularExpression('/^attachment; filename=budi_shop-\d{8}-\d{6}\.sql\.gz$/', (string) $response->headers->get('content-disposition'));
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'database.exported']);
    }

    public function test_a_failed_export_goes_back_with_a_message()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('call')->once()
            ->andThrow(new AgentException('MariaDB could not export', -32000)));

        $this->actingAs($this->account)
            ->from(route('databases.index'))
            ->get(route('databases.export', $this->database))
            ->assertRedirect(route('databases.index'));
    }

    public function test_an_uploaded_file_is_imported_as_the_account_and_removed_afterwards()
    {
        $this->actingAs($this->account)
            ->post(route('databases.import', $this->database), ['path' => 'tmp/rayxo-import-0b7c1e2a-1f2d-4c5e.sql.gz'])
            ->assertSessionHasNoErrors();

        $task = Task::query()->sole();
        $this->assertSame('db.import', $task->action);
        $this->assertSame([
            'username' => 'budi',
            'database' => 'budi_shop',
            'path' => 'tmp/rayxo-import-0b7c1e2a-1f2d-4c5e.sql.gz',
            'remove' => true,
        ], $task->payload);
        $this->assertSame('running', $this->database->fresh()->import_status);

        // A second import waits for the first.
        $this->actingAs($this->account)
            ->post(route('databases.import', $this->database), ['path' => 'backup/shop.sql'])
            ->assertSessionHasErrors(['file' => 'An import into this database is still running.']);

        $this->finish($task, TaskStatus::Failed, 'ERROR 1064 (42000) at line 3: syntax');

        $this->assertSame('failed', $this->database->fresh()->import_status);
        $this->assertSame('ERROR 1064 (42000) at line 3: syntax', $this->database->fresh()->import_error);

        // A file of the account's own is imported and kept.
        $this->actingAs($this->account)
            ->post(route('databases.import', $this->database), ['path' => 'backup/shop.sql'])
            ->assertSessionHasNoErrors();

        $second = Task::query()->latest('id')->first();
        $this->assertFalse($second->payload['remove']);

        $this->finish($second, TaskStatus::Success);

        $this->assertSame('done', $this->database->fresh()->import_status);
        $this->assertNull($this->database->fresh()->import_error);
        $this->assertNotNull($this->database->fresh()->imported_at);
    }

    #[DataProvider('invalidImportPaths')]
    public function test_only_sql_files_in_the_home_are_imported(string $path)
    {
        $this->actingAs($this->account)
            ->post(route('databases.import', $this->database), ['path' => $path])
            ->assertSessionHasErrors('path');

        $this->assertDatabaseCount('tasks', 0);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidImportPaths(): array
    {
        return [
            'outside the home' => ['/etc/shadow.sql'],
            'up the tree' => ['../sari/shop.sql'],
            'not sql' => ['public_html/index.php'],
        ];
    }

    public function test_tables_are_checked_and_the_report_comes_back()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('call')->once()
            ->with('db.maintain', ['username' => 'budi', 'database' => 'budi_shop', 'operation' => 'repair'], 600)
            ->andReturn(['lines' => ['budi_shop.orders  OK']]));

        $this->actingAs($this->account)
            ->postJson(route('databases.maintenance', $this->database), ['operation' => 'repair'])
            ->assertOk()
            ->assertExactJson(['lines' => ['budi_shop.orders  OK']]);

        $this->actingAs($this->account)
            ->postJson(route('databases.maintenance', $this->database), ['operation' => 'drop'])
            ->assertUnprocessable();
    }

    public function test_other_accounts_databases_are_not_found()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('call'));
        $other = User::factory()->create();

        $this->actingAs($other)->get(route('databases.export', $this->database))->assertNotFound();
        $this->actingAs($other)->post(route('databases.import', $this->database), ['path' => 'a.sql'])->assertNotFound();
        $this->actingAs($other)->postJson(route('databases.maintenance', $this->database), ['operation' => 'check'])->assertNotFound();

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_the_administrator_opens_and_closes_remote_access()
    {
        $admin = User::factory()->admin()->create();
        $calls = [];
        $this->mock(AgentClient::class, function (MockInterface $mock) use (&$calls): void {
            $mock->shouldReceive('call')->andReturnUsing(function (string $method, array $params) use (&$calls): array {
                $calls[] = [$method, $params];

                return ['enabled' => $params['enabled'] ?? false];
            });
        });

        $this->actingAs($admin)
            ->put(route('admin.server-settings.database-remote-access'), ['enabled' => true])
            ->assertSessionHasNoErrors();

        $this->assertTrue(app(DatabaseToolsService::class)->remoteAccessEnabled());
        $this->assertSame([['db.set_remote_access', ['enabled' => true]]], $calls);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'server.database_remote_access_changed']);

        $this->actingAs($admin)
            ->put(route('admin.server-settings.database-remote-access'), ['enabled' => false])
            ->assertSessionHasNoErrors();

        $this->assertFalse(Setting::query()->where('key', DatabaseToolsService::REMOTE_ACCESS_SETTING)->value('value'));

        $this->actingAs($this->account)
            ->put(route('admin.server-settings.database-remote-access'), ['enabled' => true])
            ->assertForbidden();
    }

    private function finish(Task $task, TaskStatus $status, ?string $error = null): void
    {
        $task->forceFill(['status' => $status, 'error' => $error])->save();

        event(new AgentTaskFinished($task));
    }
}
