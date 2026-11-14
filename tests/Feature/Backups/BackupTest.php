<?php

namespace Tests\Feature\Backups;

use App\Agent\AgentClient;
use App\Enums\TaskStatus;
use App\Jobs\RunAgentTask;
use App\Models\AuditLog;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\MailDomain;
use App\Models\Package;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\TestCase;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    private User $account;

    private Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->travelTo('2026-11-14 10:20:30');

        $this->account = User::factory()->create(['username' => 'budi', 'package_id' => Package::factory()->create(['databases' => 5, 'email_accounts' => 5])->id]);
        $this->domain = Domain::factory()->for($this->account, 'account')->create(['name' => 'tokobudi.com']);
    }

    public function test_an_account_backs_itself_up_with_the_parts_it_chooses()
    {
        $shop = Database::factory()->for($this->account, 'account')->create(['name' => 'budi_shop']);
        Database::factory()->for($this->account, 'account')->pending()->create(['name' => 'budi_new']);
        $user = DatabaseUser::factory()->for($this->account, 'account')->create(['name' => 'budi_wp', 'password_hash' => DatabaseUser::hashPassword('secret-password')]);
        $user->databases()->attach($shop);
        $mailbox = Mailbox::factory()->for(MailDomain::factory()->for($this->domain), 'mailDomain')->create(['local_part' => 'info', 'quota_mb' => 500]);

        $this->actingAs($this->account)
            ->post(route('backups.store'), ['files' => true, 'email' => false, 'databases' => true])
            ->assertSessionHasNoErrors();

        $task = Task::query()->sole();
        $this->assertSame('backup.create', $task->action);
        $this->assertSame($this->account->id, $task->user_id);
        $this->assertSame('budi-2026-11-14-102030.tar.gz', $task->payload['name']);
        $this->assertSame([true, false, ['budi_shop']], [$task->payload['files'], $task->payload['email'], $task->payload['databases']]);
        $this->assertSame(['format' => 1, 'username' => 'budi', 'files' => true, 'email' => false, 'databases' => ['budi_shop']], array_intersect_key($task->payload['manifest'], array_flip(['format', 'username', 'files', 'email', 'databases'])));
        $this->assertSame([['name' => 'budi_wp', 'password_hash' => $user->password_hash, 'databases' => ['budi_shop']]], $task->payload['settings']['database_users']);
        $this->assertSame([['domain' => 'tokobudi.com', 'local_part' => 'info', 'password_hash' => $mailbox->password_hash, 'quota_mb' => 500]], $task->payload['settings']['mailboxes']);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'backup.created', 'actor_id' => $this->account->id]);
        $this->assertSame(21600, (new RunAgentTask($task))->timeout - 300);
    }

    public function test_an_empty_or_a_second_backup_is_refused()
    {
        $this->actingAs($this->account)
            ->post(route('backups.store'), ['files' => false, 'email' => false, 'databases' => false])
            ->assertSessionHasErrors(['items' => 'Choose what the backup holds.']);

        Task::create(['user_id' => $this->account->id, 'action' => 'backup.restore', 'status' => TaskStatus::Running]);

        $this->actingAs($this->account)
            ->post(route('backups.store'), ['files' => true])
            ->assertSessionHasErrors(['backup' => 'Wait until the backup or restore that is running is done.']);

        $this->assertSame(1, Task::query()->count());
    }

    public function test_the_page_lists_the_backups_in_the_home_newest_first()
    {
        $this->agentAnswers('fs.list', ['username' => 'budi', 'path' => 'backups'], ['entries' => [
            ['name' => 'budi-2026-11-01-010000.tar.gz', 'type' => 'file', 'size' => 1000, 'modified' => 100],
            ['name' => 'uploaded-from-elsewhere.tar.gz', 'type' => 'file', 'size' => 3000, 'modified' => 300],
            ['name' => 'notes.txt', 'type' => 'file', 'size' => 5, 'modified' => 400],
            ['name' => 'old.tar.gz', 'type' => 'directory', 'size' => 0, 'modified' => 500],
        ]]);

        $this->actingAs($this->account)
            ->get(route('backups.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('backups/index')
                ->where('backups', [
                    ['name' => 'uploaded-from-elsewhere.tar.gz', 'size' => 3000, 'modified' => 300],
                    ['name' => 'budi-2026-11-01-010000.tar.gz', 'size' => 1000, 'modified' => 100],
                ])
                ->where('busy', false)
                ->missing('inspected'));
    }

    public function test_restoring_brings_back_missing_databases_their_users_and_mailboxes_first()
    {
        Database::factory()->for($this->account, 'account')->create(['name' => 'budi_shop']);
        $mailDomain = MailDomain::factory()->for($this->domain)->create();
        Mailbox::factory()->for($mailDomain, 'mailDomain')->create(['local_part' => 'info']);
        $oldHash = DatabaseUser::hashPassword('old-db-password');
        $mailHash = Mailbox::hashPassword('old-mail-password');

        $this->agentAnswers('backup.inspect', ['username' => 'budi', 'name' => 'b.tar.gz'], $this->meta(['budi_shop', 'budi_old'], [
            'database_users' => [['name' => 'budi_olduser', 'password_hash' => $oldHash, 'databases' => ['budi_old', 'budi_shop']]],
            'mailboxes' => [
                ['domain' => 'tokobudi.com', 'local_part' => 'info', 'password_hash' => $mailHash, 'quota_mb' => null],
                ['domain' => 'tokobudi.com', 'local_part' => 'sales', 'password_hash' => $mailHash, 'quota_mb' => 200],
                ['domain' => 'gone.example', 'local_part' => 'x', 'password_hash' => $mailHash, 'quota_mb' => null],
            ],
        ]));

        $this->actingAs($this->account)
            ->post(route('backups.restore', 'b.tar.gz'), ['files' => true, 'email' => true, 'databases' => ['budi_old', 'budi_shop']])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('backups.index'));

        $actions = Task::query()->orderBy('id')->pluck('action')->all();
        $this->assertSame('db.create_database', $actions[0]);
        $this->assertContains('db.apply_user', $actions);
        $this->assertSame('backup.restore', end($actions));
        $this->assertSame(['username' => 'budi', 'name' => 'b.tar.gz', 'files' => true, 'email' => true, 'databases' => ['budi_old', 'budi_shop']], Task::query()->latest('id')->first()->payload);

        $this->assertSame($oldHash, DatabaseUser::query()->where('name', 'budi_olduser')->sole()->password_hash);
        $this->assertSame(['budi_old', 'budi_shop'], DatabaseUser::query()->where('name', 'budi_olduser')->sole()->databases()->orderBy('name')->pluck('name')->all());
        $this->assertSame($mailHash, Mailbox::query()->where('local_part', 'sales')->sole()->password_hash);
        $this->assertSame(200, Mailbox::query()->where('local_part', 'sales')->sole()->quota_mb);
        $this->assertSame(2, Mailbox::query()->count());
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'backup.restored']);
    }

    public function test_a_backup_of_another_account_or_a_database_it_does_not_hold_is_not_restored()
    {
        $this->mock(AgentClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('call')->with('backup.inspect', ['username' => 'budi', 'name' => 'b.tar.gz'])
                ->andReturn(['manifest' => json_encode(['format' => 1, 'username' => 'sari', 'files' => true]), 'settings' => '{}']);
            $mock->shouldReceive('call')->with('backup.inspect', ['username' => 'budi', 'name' => 'c.tar.gz'])
                ->andReturn($this->meta(['budi_shop'], []));
        });

        $this->actingAs($this->account)
            ->post(route('backups.restore', 'b.tar.gz'), ['files' => true])
            ->assertSessionHasErrors(['backup' => 'This backup belongs to another account.']);

        $this->actingAs($this->account)
            ->post(route('backups.restore', 'c.tar.gz'), ['databases' => ['budi_other']])
            ->assertSessionHasErrors(['databases' => 'The backup does not hold database budi_other.']);

        $this->assertSame(0, Task::query()->count());
    }

    public function test_a_damaged_password_in_the_backup_restores_nothing()
    {
        $this->agentAnswers('backup.inspect', ['username' => 'budi', 'name' => 'b.tar.gz'], $this->meta(['budi_old'], [
            'database_users' => [['name' => 'budi_x', 'password_hash' => "*' OR 1=1", 'databases' => ['budi_old']]],
        ]));

        $this->actingAs($this->account)
            ->post(route('backups.restore', 'b.tar.gz'), ['databases' => ['budi_old']])
            ->assertSessionHasErrors(['backup' => 'The password of database user budi_x in the backup is damaged.']);

        $this->assertSame(0, Task::query()->count());
        $this->assertDatabaseMissing('databases', ['name' => 'budi_old']);
    }

    public function test_the_restore_dialog_reads_what_a_backup_holds()
    {
        $this->agentAnswers('backup.inspect', ['username' => 'budi', 'name' => 'b.tar.gz'], $this->meta(['budi_shop'], [
            'mailboxes' => [['domain' => 'tokobudi.com', 'local_part' => 'info', 'password_hash' => 'x', 'quota_mb' => null]],
        ]), allowList: true);

        $this->actingAs($this->account)
            ->get(route('backups.index', ['restore' => 'b.tar.gz']))
            ->assertInertia(fn (Assert $page) => $page->reloadOnly('inspected', fn (Assert $reload) => $reload
                ->where('inspected.files', true)
                ->where('inspected.databases', ['budi_shop'])
                ->where('inspected.mailboxes', ['info@tokobudi.com'])
                ->missing('inspected.settings')));
    }

    public function test_a_backup_is_deleted_for_good()
    {
        $this->agentAnswers('backup.delete', ['username' => 'budi', 'name' => 'old.tar.gz'], []);

        $this->actingAs($this->account)
            ->delete(route('backups.destroy', 'old.tar.gz'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas(AuditLog::class, ['action' => 'backup.deleted']);
    }

    public function test_only_hosting_accounts_have_backups_here()
    {
        foreach ([User::factory()->admin()->create(), User::factory()->reseller()->create()] as $other) {
            $this->actingAs($other)->get(route('backups.index'))->assertForbidden();
        }
    }

    /**
     * @param  list<string>  $databases
     * @param  array<string, mixed>  $settings
     * @return array{manifest: string, settings: string}
     */
    private function meta(array $databases, array $settings): array
    {
        return [
            'manifest' => (string) json_encode(['format' => 1, 'username' => 'budi', 'created_at' => '2026-11-13T01:00:00+00:00', 'files' => true, 'email' => true, 'databases' => $databases]),
            'settings' => (string) json_encode(['format' => 1, ...$settings]),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $answer
     */
    private function agentAnswers(string $method, array $params, array $answer, bool $allowList = false): void
    {
        $this->mock(AgentClient::class, function (MockInterface $mock) use ($method, $params, $answer, $allowList) {
            $mock->shouldReceive('call')->with($method, $params)->andReturn($answer);

            if ($allowList) {
                $mock->shouldReceive('call')->with('fs.list', ['username' => 'budi', 'path' => 'backups'])->andReturn(['entries' => []]);
            }
        });
    }
}
