<?php

namespace Tests\Feature\Backups;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Enums\AccountStatus;
use App\Enums\ServerBackupStatus;
use App\Jobs\CreateServerBackup;
use App\Jobs\TransferAccount;
use App\Models\AccountRestore;
use App\Models\AccountTransfer;
use App\Models\AuditLog;
use App\Models\LoginKey;
use App\Models\Package;
use App\Models\ServerBackup;
use App\Models\User;
use App\Services\AccountTransferService;
use App\Services\ServerBackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Accounts moved between RAYXO servers: the API the old server answers,
 * the backups found in the folder, and the move driven by the new server.
 */
class TransferTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'rxk_admin6789abcdefghijABCDEFGHIJ0123456789';

    private const RESELLER_KEY = 'rxk_resel6789abcdefghijABCDEFGHIJ0123456789';

    private const SOURCE = 'https://old.example.com:2222';

    private User $admin;

    /**
     * @var list<array{0: string, 1: array<string, mixed>}>
     */
    private array $agentCalls = [];

    /**
     * @var array<string, mixed>
     */
    private array $answers = [];

    /**
     * The permissions of the login key the old panel reports.
     *
     * @var list<string>
     */
    private array $sourcePermissions = AccountTransferService::PERMISSIONS;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Sleep::fake();
        $this->travelTo('2026-11-14 10:00:00');

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

    public function test_the_old_server_backs_an_account_up_and_hands_it_over_through_its_api()
    {
        $reseller = User::factory()->reseller()->create();
        LoginKey::factory()->for($this->admin)->withSecret(self::KEY)->create(['permissions' => ['accounts:read', 'backups:read', 'backups:write']]);
        LoginKey::factory()->for($reseller)->withSecret(self::RESELLER_KEY)->create(['permissions' => ['accounts:read', 'backups:read']]);
        $budi = User::factory()->create(['username' => 'budi']);

        $created = $this->withToken(self::KEY)->postJson('/api/v1/accounts/budi/backups')
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.username', 'budi')
            ->json('data');
        Queue::assertPushed(CreateServerBackup::class);

        $backup = ServerBackup::query()->findOrFail($created['id']);
        $backup->forceFill(['status' => ServerBackupStatus::Completed, 'size_bytes' => 6])->save();

        $dir = storage_path('framework/testing/backups-'.uniqid());
        config(['hosting.backups.dir' => $dir]);
        File::ensureDirectoryExists($dir);
        File::put($backup->path(), 'backup');

        try {
            $this->withToken(self::KEY)->getJson('/api/v1/backups/'.$backup->id)->assertOk()->assertJsonPath('data.status', 'completed');
            $this->withToken(self::KEY)->get('/api/v1/backups/'.$backup->id.'/download')->assertOk()->assertDownload($backup->name);
            $this->withToken(self::KEY)->getJson('/api/v1/accounts/budi/backups')->assertJsonPath('data.*.id', [$backup->id]);

            // A reseller sees only the backups of its own accounts, and a
            // key without backups:write makes none.
            $this->withToken(self::RESELLER_KEY)->getJson('/api/v1/backups/'.$backup->id)->assertNotFound();
            $this->withToken(self::RESELLER_KEY)->get('/api/v1/backups/'.$backup->id.'/download')->assertNotFound();
            $this->withToken(self::RESELLER_KEY)->postJson('/api/v1/accounts/budi/backups')->assertForbidden();
        } finally {
            File::deleteDirectory($dir);
        }

        $this->assertDatabaseHas(AuditLog::class, ['action' => 'backup.server_downloaded', 'actor_id' => $this->admin->id]);
        $this->assertSame(1, ServerBackup::query()->where('user_id', $budi->id)->count());
    }

    public function test_backups_copied_into_the_folder_by_hand_are_found()
    {
        $budi = User::factory()->create(['username' => 'budi']);
        $known = ServerBackup::factory()->for($budi, 'account')->create();
        $this->answers['backup.server_list'] = ['backups' => [
            ['name' => $known->name, 'size' => 1],
            ['name' => 'budi-old.tar.gz', 'size' => 300],
            ['name' => 'lama-old.tar.gz', 'size' => 400],
            ['name' => 'junk.tar.gz', 'size' => 5],
        ]];
        $this->answers['backup.server_inspect'] = fn (array $params): array => match ($params['name']) {
            'junk.tar.gz' => throw new AgentException('the file is not a RAYXO backup', reason: 'invalid'),
            default => ['manifest' => (string) json_encode(['format' => 1, 'username' => strstr($params['name'], '-', true), 'files' => true, 'email' => false, 'databases' => ['budi_shop', 'other_x']]), 'settings' => '{}'],
        };

        $this->actingAs($this->admin)
            ->post(route('admin.backups.scan'))
            ->assertSessionHas('inertia.flash_data.toast.message', 'Found 2 new backup(s) in the backups folder. Not RAYXO backups: junk.tar.gz');

        $found = ServerBackup::query()->where('name', 'budi-old.tar.gz')->sole();
        $this->assertSame([$budi->id, 'budi', true, false, ['budi_shop'], 300, ServerBackupStatus::Completed], [$found->user_id, $found->username, $found->files, $found->email, $found->databases, $found->size_bytes, $found->status]);

        $deleted = ServerBackup::query()->where('name', 'lama-old.tar.gz')->sole();
        $this->assertSame([null, 'lama', $this->admin->id], [$deleted->user_id, $deleted->username, $deleted->owner_id]);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'backup.server_scanned', 'actor_id' => $this->admin->id]);
    }

    public function test_the_admin_connects_to_the_old_panel_and_sees_which_accounts_can_move()
    {
        User::factory()->create(['username' => 'sari']);
        $this->fakeSource();

        $this->actingAs($this->admin)
            ->post(route('admin.transfers.connect'), ['source_url' => self::SOURCE.'/login', 'api_key' => self::KEY, 'verify_tls' => true])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('inertia.flash_data.transfer_source', [
                'source' => ['username' => 'oldadmin', 'role' => 'admin'],
                'accounts' => [
                    ['username' => 'lama', 'domain' => 'lama.example', 'package' => 'Bisnis', 'status' => 'active', 'taken' => false],
                    ['username' => 'sari', 'domain' => null, 'package' => null, 'status' => 'active', 'taken' => true],
                ],
            ]);

        Http::assertSent(fn (Request $request) => $request->url() === self::SOURCE.'/api/v1/me' && $request->hasHeader('Authorization', 'Bearer '.self::KEY));

        // Only https, and a key that may move accounts.
        $this->actingAs($this->admin)
            ->post(route('admin.transfers.connect'), ['source_url' => 'http://old.example.com:2222', 'api_key' => self::KEY, 'verify_tls' => true])
            ->assertSessionHasErrors('source_url');

        $this->sourcePermissions = ['accounts:read'];

        $this->actingAs($this->admin)
            ->post(route('admin.transfers.connect'), ['source_url' => self::SOURCE, 'api_key' => self::KEY, 'verify_tls' => true])
            ->assertSessionHasErrors(['api_key' => 'The key needs these permissions on the other panel: backups:read, backups:write.']);
    }

    public function test_moving_queues_one_job_per_account_and_refuses_taken_usernames()
    {
        User::factory()->create(['username' => 'sari']);
        $this->fakeSource();

        $this->actingAs($this->admin)
            ->post(route('admin.transfers.store'), ['source_url' => self::SOURCE, 'api_key' => self::KEY, 'verify_tls' => true, 'usernames' => ['lama', 'sari']])
            ->assertSessionHasErrors(['usernames' => 'These usernames are taken here: sari.']);

        $this->actingAs($this->admin)
            ->post(route('admin.transfers.store'), ['source_url' => self::SOURCE, 'api_key' => self::KEY, 'verify_tls' => false, 'usernames' => ['lama']])
            ->assertSessionHasNoErrors();

        $transfer = AccountTransfer::query()->sole();
        $this->assertSame([self::SOURCE, self::KEY, false, 'lama', AccountTransfer::STATUS_QUEUED], [$transfer->source_url, $transfer->api_key, $transfer->verify_tls, $transfer->username, $transfer->status]);
        $this->assertNotSame(self::KEY, AccountTransfer::query()->toBase()->value('api_key'));
        Queue::assertPushedOn(ServerBackupService::QUEUE, TransferAccount::class);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'backup.transfer_started', 'actor_id' => $this->admin->id]);
    }

    public function test_a_move_backs_the_account_up_there_downloads_it_and_makes_it_again_here()
    {
        $package = Package::factory()->for($this->admin, 'owner')->create(['name' => 'Bisnis']);
        $transfer = AccountTransfer::factory()->create(['username' => 'lama', 'source_url' => self::SOURCE, 'api_key' => self::KEY, 'actor_id' => $this->admin->id]);
        $meta = [
            'manifest' => (string) json_encode(['format' => 1, 'username' => 'lama', 'files' => true, 'email' => true, 'databases' => []]),
            'settings' => (string) json_encode(['format' => 1, 'account' => ['name' => 'Pak Lama', 'email' => 'lama@example.com', 'password_hash' => Hash::make('Old-Password-123'), 'package' => 'Bisnis'], 'domains' => []]),
        ];
        $this->answers['backup.server_import'] = fn (array $params): array => ['name' => $params['name'], 'size' => 7, ...$meta];
        $this->answers['backup.server_inspect'] = $meta;

        $polls = 0;
        Http::fake([
            self::SOURCE.'/api/v1/accounts/lama/backups' => Http::response(['data' => ['id' => 41, 'status' => 'queued']], 202),
            self::SOURCE.'/api/v1/backups/41' => function () use (&$polls) {
                return Http::response(['data' => ['id' => 41, 'status' => ++$polls > 1 ? 'completed' : 'running', 'size' => 7]]);
            },
            self::SOURCE.'/api/v1/backups/41/download' => Http::response('archive'),
        ]);

        app(AccountTransferService::class)->run($transfer);

        $transfer->refresh();
        $this->assertSame([AccountTransfer::STATUS_RESTORING, null, 41], [$transfer->status, $transfer->api_key, $transfer->remote_backup_id], (string) $transfer->error);

        [, $import] = collect($this->agentCalls)->firstWhere(0, 'backup.server_import');
        $this->assertMatchesRegularExpression('/^lama-2026-11-14-\d{6}-transfer\.tar\.gz$/', $import['name']);

        $account = User::query()->where('username', 'lama')->sole();
        $this->assertSame([AccountStatus::Pending, $package->id, $this->admin->id, $transfer->user_id], [$account->status, $account->package_id, $account->owner_id, $account->id]);
        $this->assertTrue(Hash::check('Old-Password-123', $account->password));
        $this->assertSame(AccountRestore::STATUS_WAITING, AccountRestore::query()->sole()->status);
        $this->assertSame('lama', ServerBackup::query()->findOrFail($transfer->server_backup_id)->username);
    }

    public function test_a_move_the_old_server_cannot_back_up_fails_and_forgets_the_key()
    {
        $transfer = AccountTransfer::factory()->create(['username' => 'lama', 'source_url' => self::SOURCE, 'api_key' => self::KEY, 'actor_id' => $this->admin->id]);
        Http::fake([
            self::SOURCE.'/api/v1/accounts/lama/backups' => Http::response(['data' => ['id' => 41, 'status' => 'queued']], 202),
            self::SOURCE.'/api/v1/backups/41' => Http::response(['data' => ['id' => 41, 'status' => 'failed', 'error' => 'disk full']]),
        ]);

        app(AccountTransferService::class)->run($transfer);

        $transfer->refresh();
        $this->assertSame([AccountTransfer::STATUS_FAILED, null, 'The other panel could not back the account up: disk full'], [$transfer->status, $transfer->api_key, $transfer->error]);
        $this->assertFalse(User::query()->where('username', 'lama')->exists());
    }

    /**
     * The old panel's API: who the key is, and two accounts.
     */
    private function fakeSource(): void
    {
        Http::fake([
            self::SOURCE.'/api/v1/me' => fn () => Http::response(['data' => ['username' => 'oldadmin', 'role' => 'admin', 'login_key' => ['permissions' => $this->sourcePermissions]]]),
            self::SOURCE.'/api/v1/accounts*' => Http::response([
                'data' => [
                    ['username' => 'lama', 'status' => 'active', 'primary_domain' => 'lama.example', 'package' => ['id' => 3, 'name' => 'Bisnis']],
                    ['username' => 'sari', 'status' => 'active', 'primary_domain' => null, 'package' => null],
                ],
                'meta' => ['last_page' => 1],
            ]),
        ]);
    }
}
