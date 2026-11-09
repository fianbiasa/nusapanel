<?php

namespace Tests\Feature\Databases;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Enums\AccountStatus;
use App\Enums\DatabaseStatus;
use App\Jobs\RunAgentTask;
use App\Models\AuditLog;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\Package;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DatabaseManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_admins_cannot_reach_databases()
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('databases.index'))
            ->assertForbidden();
    }

    public function test_the_page_lists_the_accounts_databases_and_users()
    {
        $account = User::factory()->create(['username' => 'budi']);
        $shop = Database::factory()->for($account, 'account')->create(['name' => 'budi_shop', 'size_bytes' => 2048]);
        $user = DatabaseUser::factory()->for($account, 'account')->create(['name' => 'budi_wp']);
        $user->databases()->attach($shop);
        Database::factory()->create();

        $this->actingAs($account)
            ->get(route('databases.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('databases/index')
                ->has('databases', 1)
                ->where('databases.0.name', 'budi_shop')
                ->where('databases.0.size_bytes', 2048)
                ->where('databases.0.users', ['budi_wp'])
                ->has('database_users', 1)
                ->where('database_users.0.name', 'budi_wp')
                ->where('database_users.0.database_ids', [$shop->id])
                ->missing('database_users.0.password_hash')
                ->where('prefix', 'budi_')
                ->where('max_lengths', ['database' => 59, 'user' => 27])
                ->where('connection', ['host' => 'localhost', 'port' => 3306]));
    }

    public function test_accounts_can_create_a_database_with_a_user()
    {
        $account = User::factory()->create(['username' => 'budi']);

        $this->actingAs($account)
            ->post(route('databases.store'), [
                'name' => ' Budi_Shop ',
                'create_user' => '1',
                'user_name' => 'wp',
                'password' => 'correct horse battery',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('databases.index'));

        $database = Database::query()->sole();
        $user = DatabaseUser::query()->sole();

        $this->assertSame('budi_shop', $database->name);
        $this->assertSame(DatabaseStatus::Pending, $database->status);
        $this->assertSame('budi_wp', $user->name);
        $this->assertSame(DatabaseUser::hashPassword('correct horse battery'), $user->password_hash);
        $this->assertTrue($user->databases->first()->is($database));

        // The password itself never reaches the agent, only its hash.
        $this->assertSame([
            ['action' => 'db.create_database', 'payload' => ['username' => 'budi', 'database' => 'budi_shop']],
            ['action' => 'db.apply_user', 'payload' => [
                'username' => 'budi',
                'user' => 'budi_wp',
                'password_hash' => '*'.strtoupper(sha1(sha1('correct horse battery', true))),
                'databases' => ['budi_shop'],
            ]],
        ], Task::query()->orderBy('id')->get()->map(fn (Task $task): array => ['action' => $task->action, 'payload' => $task->payload])->all());

        $this->assertStringNotContainsString('correct horse', (string) json_encode(AuditLog::query()->pluck('changes')));
    }

    public function test_a_database_can_be_created_without_a_user()
    {
        $account = User::factory()->create(['username' => 'budi']);

        $this->actingAs($account)
            ->post(route('databases.store'), ['name' => 'blog', 'create_user' => '0'])
            ->assertSessionHasNoErrors();

        $this->assertSame('budi_blog', Database::query()->sole()->name);
        $this->assertDatabaseCount('database_users', 0);
        $this->assertSame(['db.create_database'], Task::query()->pluck('action')->all());
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function invalidDatabases(): array
    {
        return [
            'no name' => [['name' => ''], 'name'],
            'a hyphen' => [['name' => 'my-shop'], 'name'],
            'quotes' => [['name' => "shop'; DROP DATABASE mysql; --"], 'name'],
            'too long' => [['name' => str_repeat('a', 60)], 'name'],
            'user without password' => [['user_name' => 'wp', 'password' => ''], 'password'],
            'short password' => [['user_name' => 'wp', 'password' => 'short'], 'password'],
            'user name too long' => [['user_name' => str_repeat('u', 28), 'password' => 'long enough password'], 'user_name'],
        ];
    }

    /**
     * @param  array<string, string>  $overrides
     */
    #[DataProvider('invalidDatabases')]
    public function test_invalid_databases_are_refused(array $overrides, string $field)
    {
        $account = User::factory()->create(['username' => 'budi']);

        $this->actingAs($account)
            ->post(route('databases.store'), [
                'name' => 'shop',
                'create_user' => isset($overrides['user_name']) ? '1' : '0',
                ...$overrides,
            ])
            ->assertSessionHasErrors($field);

        $this->assertDatabaseCount('databases', 0);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_a_name_can_be_used_once()
    {
        $account = User::factory()->create(['username' => 'budi']);
        Database::factory()->for($account, 'account')->create(['name' => 'budi_shop']);

        $this->actingAs($account)
            ->post(route('databases.store'), ['name' => 'shop', 'create_user' => '0'])
            ->assertSessionHasErrors(['name' => 'This name is already in use.']);

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_databases_count_against_the_package_limit()
    {
        $account = User::factory()->create([
            'username' => 'budi',
            'package_id' => Package::factory()->create(['databases' => 1])->id,
        ]);
        Database::factory()->for($account, 'account')->create(['name' => 'budi_one']);

        $this->actingAs($account)
            ->post(route('databases.store'), ['name' => 'two', 'create_user' => '0'])
            ->assertSessionHasErrors([
                'name' => 'Your package allows 1 databases. Remove a database or ask your provider for a bigger package.',
            ]);

        $this->assertDatabaseCount('databases', 1);
    }

    public function test_suspended_accounts_cannot_change_databases()
    {
        $account = User::factory()->create(['username' => 'budi', 'status' => AccountStatus::Suspended]);

        // A suspended account is signed out before it gets anywhere.
        $this->actingAs($account)
            ->post(route('databases.store'), ['name' => 'shop', 'create_user' => '0'])
            ->assertSessionHasErrors(['username' => 'This account is suspended or not active yet.']);

        $this->assertDatabaseCount('databases', 0);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_users_can_be_given_a_new_password_and_other_databases()
    {
        $account = User::factory()->create(['username' => 'budi']);
        $shop = Database::factory()->for($account, 'account')->create(['name' => 'budi_shop']);
        $blog = Database::factory()->for($account, 'account')->create(['name' => 'budi_blog']);
        $user = DatabaseUser::factory()->for($account, 'account')->create(['name' => 'budi_wp']);
        $user->databases()->attach($shop);

        $this->actingAs($account)
            ->put(route('database-users.update', $user), ['password' => 'a brand new password', 'databases' => [$blog->id]])
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame(DatabaseUser::hashPassword('a brand new password'), $user->password_hash);
        $this->assertSame(DatabaseStatus::Pending, $user->status);
        $this->assertSame([$blog->id], $user->databases->pluck('id')->all());

        $this->assertSame([
            'username' => 'budi',
            'user' => 'budi_wp',
            'password_hash' => DatabaseUser::hashPassword('a brand new password'),
            'databases' => ['budi_blog'],
        ], Task::query()->sole()->payload);
    }

    public function test_an_empty_password_keeps_the_current_one()
    {
        $account = User::factory()->create(['username' => 'budi']);
        $user = DatabaseUser::factory()->for($account, 'account')->create(['name' => 'budi_wp']);
        $hash = $user->password_hash;

        $this->actingAs($account)
            ->put(route('database-users.update', $user), ['password' => '', 'databases' => []])
            ->assertSessionHasNoErrors();

        $this->assertSame($hash, $user->fresh()->password_hash);
        $this->assertSame($hash, Task::query()->sole()->payload['password_hash']);
    }

    public function test_users_only_get_databases_of_their_own_account()
    {
        $account = User::factory()->create(['username' => 'budi']);
        $user = DatabaseUser::factory()->for($account, 'account')->create(['name' => 'budi_wp']);
        $foreign = Database::factory()->create();

        $this->actingAs($account)
            ->put(route('database-users.update', $user), ['databases' => [$foreign->id]])
            ->assertSessionHasErrors(['databases' => 'Choose databases of this account.']);

        $this->actingAs($account)
            ->post(route('database-users.store'), ['name' => 'other', 'password' => 'long enough password', 'databases' => [$foreign->id]])
            ->assertSessionHasErrors('databases');

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_accounts_cannot_touch_another_accounts_databases_or_users()
    {
        $database = Database::factory()->create();
        $user = DatabaseUser::factory()->create();
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->delete(route('databases.destroy', $database))->assertNotFound();
        $this->actingAs($intruder)->delete(route('database-users.destroy', $user))->assertNotFound();
        $this->actingAs($intruder)->put(route('database-users.update', $user), ['databases' => []])->assertNotFound();

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_deleting_a_database_drops_it_and_the_record_goes_once_it_is_gone()
    {
        $account = User::factory()->create(['username' => 'budi']);
        $database = Database::factory()->for($account, 'account')->create(['name' => 'budi_shop']);
        $user = DatabaseUser::factory()->for($account, 'account')->create(['name' => 'budi_wp']);
        $user->databases()->attach($database);

        $this->actingAs($account)
            ->delete(route('databases.destroy', $database))
            ->assertSessionHasNoErrors();

        $this->assertSame(DatabaseStatus::Deleting, $database->fresh()->status);

        $this->runTask(Task::query()->sole(), []);

        $this->assertModelMissing($database);
        $this->assertSame([], $user->fresh()->databases->all());
    }

    public function test_task_results_settle_the_records()
    {
        $account = User::factory()->create(['username' => 'budi']);

        $this->actingAs($account)
            ->post(route('databases.store'), ['name' => 'shop', 'create_user' => '1', 'user_name' => 'wp', 'password' => 'long enough password']);

        [$create, $apply] = Task::query()->orderBy('id')->get()->all();

        $this->runTask($create, []);
        $this->runTask($apply, new AgentException('MariaDB could not set up database user budi_wp'));

        $this->assertSame(DatabaseStatus::Active, Database::query()->sole()->status);
        $this->assertSame(DatabaseStatus::Failed, DatabaseUser::query()->sole()->status);

        // A failed user can be deleted again.
        $user = DatabaseUser::query()->sole();
        $this->actingAs($account)->delete(route('database-users.destroy', $user))->assertSessionHasNoErrors();
        $this->runTask(Task::query()->where('action', 'db.delete_user')->sole(), []);

        $this->assertModelMissing($user);
    }

    public function test_records_waiting_on_the_agent_cannot_change()
    {
        $account = User::factory()->create(['username' => 'budi']);
        $database = Database::factory()->for($account, 'account')->pending()->create(['name' => 'budi_shop']);
        $user = DatabaseUser::factory()->for($account, 'account')->pending()->create(['name' => 'budi_wp']);

        $this->actingAs($account)->delete(route('databases.destroy', $database))->assertSessionHasErrors('database');
        $this->actingAs($account)->delete(route('database-users.destroy', $user))->assertSessionHasErrors('user');
        $this->actingAs($account)->put(route('database-users.update', $user), ['databases' => []])->assertSessionHasErrors('databases');

        $this->assertDatabaseCount('tasks', 0);
    }

    /**
     * Run the task against a mocked agent that returns output, or throws.
     *
     * @param  array<string, mixed>|AgentException  $result
     */
    private function runTask(Task $task, array|AgentException $result): void
    {
        $agent = Mockery::mock(AgentClient::class);
        $call = $agent->shouldReceive('call')->once();

        $result instanceof AgentException ? $call->andThrow($result) : $call->andReturn($result);

        (new RunAgentTask($task))->handle($agent);
    }
}
