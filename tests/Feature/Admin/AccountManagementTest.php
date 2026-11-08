<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountStatus;
use App\Enums\DomainStatus;
use App\Enums\Role;
use App\Jobs\RunAgentTask;
use App\Models\AccountUsage;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Package;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_admins_can_list_accounts_without_seeing_secrets()
    {
        $admin = User::factory()->admin()->create();
        $package = Package::factory()->create(['name' => 'Basic']);
        $account = User::factory()->for($admin, 'owner')->suspended('Unpaid invoice')->create([
            'username' => 'budi',
            'name' => 'Budi Santoso',
            'email' => 'budi@example.com',
            'package_id' => $package->id,
        ]);
        Domain::factory()->for($account, 'account')->primary()->create(['name' => 'xn--bcher-kva.de']);
        Domain::factory()->for($account, 'account')->create(['name' => 'second.com']);
        AccountUsage::factory()->for($account, 'account')->create(['disk_kb' => 120 * 1024 + 1]);

        $response = $this->actingAs($admin)->get(route('admin.accounts.index'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/accounts/index')
                ->where('filters.search', '')
                ->has('accounts.data', 2)
                ->has('accounts.data.0', fn (Assert $row) => $row
                    ->where('id', $account->id)
                    ->where('username', 'budi')
                    ->where('name', 'Budi Santoso')
                    ->where('email', 'budi@example.com')
                    ->where('role', 'user')
                    ->where('status', 'suspended')
                    ->where('suspend_reason', 'Unpaid invoice')
                    ->where('owner', $admin->username)
                    ->where('package_id', $package->id)
                    ->where('package', 'Basic')
                    ->where('domain', 'bücher.de')
                    ->where('disk', ['used_mb' => 121, 'limit_mb' => 1024])
                    ->has('created_at'))
                ->where('accounts.data.1.disk', ['used_mb' => null, 'limit_mb' => null]));

        $response->assertDontSee($account->password, false);
    }

    public function test_admins_can_search_accounts_by_username_name_or_email()
    {
        $admin = User::factory()->admin()->create(['username' => 'root1', 'name' => 'Admin', 'email' => 'admin@panel.test']);
        User::factory()->create(['username' => 'budi', 'name' => 'Budi Santoso', 'email' => 'budi@example.com']);
        User::factory()->create(['username' => 'siti', 'name' => 'Siti Aminah', 'email' => 'siti@shop.test']);

        foreach (['bud' => 'budi', 'Aminah' => 'siti', 'shop.test' => 'siti'] as $search => $expected) {
            $this->actingAs($admin)
                ->get(route('admin.accounts.index', ['search' => $search]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('filters.search', $search)
                    ->has('accounts.data', 1)
                    ->where('accounts.data.0.username', $expected));
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function accountActions(): array
    {
        return [
            'store' => ['post', 'admin.accounts.store'],
            'suspend' => ['post', 'admin.accounts.suspend'],
            'unsuspend' => ['post', 'admin.accounts.unsuspend'],
            'destroy' => ['delete', 'admin.accounts.destroy'],
        ];
    }

    #[DataProvider('accountActions')]
    public function test_users_and_resellers_cannot_change_accounts(string $method, string $route)
    {
        $account = User::factory()->create();

        foreach ([User::factory()->create(), User::factory()->reseller()->create()] as $actor) {
            $this->actingAs($actor)
                ->{$method}(route($route, $route === 'admin.accounts.store' ? [] : $account), $this->validAccountData())
                ->assertForbidden();
        }

        $this->assertSame(AccountStatus::Active, $account->fresh()->status);
        $this->assertDatabaseMissing('users', ['username' => 'budi']);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_admins_can_create_an_account_that_the_agent_then_sets_up()
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.accounts.store'), $this->validAccountData())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.accounts.index'));

        $account = User::query()->where('username', 'budi')->sole();

        $this->assertSame(Role::User, $account->role);
        $this->assertSame(AccountStatus::Pending, $account->status);
        $this->assertSame($admin->id, $account->owner_id);
        $this->assertNotNull($account->email_verified_at);
        $this->assertTrue(Hash::check('correct-horse-battery', $account->password));

        $task = Task::query()->sole();

        $this->assertSame('account.create', $task->action);
        $this->assertSame(['username' => 'budi'], $task->payload);
        $this->assertSame($account->id, $task->user_id);
        $this->assertSame($admin->id, $task->actor_id);

        Queue::assertPushed(RunAgentTask::class, fn (RunAgentTask $job) => $job->task->is($task));

        $this->assertDatabaseHas(AuditLog::class, [
            'action' => 'account.created',
            'actor_id' => $admin->id,
            'subject_id' => $account->id,
        ]);
    }

    public function test_admins_can_create_an_account_with_a_primary_domain_that_waits_for_the_account()
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.accounts.store'), $this->validAccountData(['domain' => 'Example.com']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.accounts.index'));

        $account = User::query()->where('username', 'budi')->sole();
        $domain = Domain::query()->sole();

        $this->assertSame('example.com', $domain->name);
        $this->assertSame($account->id, $domain->user_id);
        $this->assertTrue($domain->is_primary);
        $this->assertSame(DomainStatus::Pending, $domain->status);

        $this->assertSame('account.create', Task::query()->sole()->action);
        Queue::assertPushed(RunAgentTask::class, 1);
    }

    public function test_an_account_is_not_created_when_its_primary_domain_is_in_use()
    {
        $admin = User::factory()->admin()->create();
        Domain::factory()->create(['name' => 'example.com']);

        $this->actingAs($admin)
            ->post(route('admin.accounts.store'), $this->validAccountData(['domain' => 'example.com']))
            ->assertSessionHasErrors(['domain' => 'This domain is already in use by another account.']);

        $this->assertDatabaseMissing('users', ['username' => 'budi']);
        $this->assertDatabaseCount('domains', 1);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_invalid_primary_domains_are_rejected()
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.accounts.store'), $this->validAccountData(['domain' => 'www.example.com']))
            ->assertSessionHasErrors(['domain' => 'Add the domain without "www." — www is served automatically.']);

        $this->assertDatabaseMissing('users', ['username' => 'budi']);
        Queue::assertNothingPushed();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidUsernames(): array
    {
        return [
            'reserved by the system' => ['root'],
            'not a valid Linux user name' => ['Budi_1'],
        ];
    }

    #[DataProvider('invalidUsernames')]
    public function test_invalid_usernames_are_rejected(string $username)
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.accounts.store'), $this->validAccountData(['username' => $username]))
            ->assertSessionHasErrors('username');

        $this->assertDatabaseMissing('users', ['email' => 'budi@example.com']);
        Queue::assertNothingPushed();
    }

    public function test_usernames_must_be_unique()
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['username' => 'budi']);

        $this->actingAs($admin)
            ->post(route('admin.accounts.store'), $this->validAccountData())
            ->assertSessionHasErrors('username');

        Queue::assertNothingPushed();
    }

    public function test_admins_can_suspend_an_account()
    {
        $admin = User::factory()->admin()->create();
        $account = User::factory()->create(['username' => 'budi']);

        $this->actingAs($admin)
            ->from(route('admin.accounts.index'))
            ->post(route('admin.accounts.suspend', $account), ['reason' => 'Unpaid invoice'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.accounts.index'));

        $account->refresh();

        $this->assertSame(AccountStatus::Suspended, $account->status);
        $this->assertSame('Unpaid invoice', $account->suspend_reason);
        $this->assertQueuedTask('account.suspend', $account);
    }

    public function test_admins_can_unsuspend_an_account()
    {
        $admin = User::factory()->admin()->create();
        $account = User::factory()->suspended()->create(['username' => 'budi']);

        $this->actingAs($admin)
            ->from(route('admin.accounts.index'))
            ->post(route('admin.accounts.unsuspend', $account))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.accounts.index'));

        $account->refresh();

        $this->assertSame(AccountStatus::Active, $account->status);
        $this->assertNull($account->suspend_reason);
        $this->assertQueuedTask('account.unsuspend', $account);
    }

    public function test_admins_can_delete_an_account()
    {
        $admin = User::factory()->admin()->create();
        $account = User::factory()->create(['username' => 'budi']);

        $this->actingAs($admin)
            ->from(route('admin.accounts.index'))
            ->delete(route('admin.accounts.destroy', $account))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.accounts.index'));

        $this->assertSame(AccountStatus::Deleting, $account->fresh()->status);
        $this->assertQueuedTask('account.delete', $account);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function protectedAccountActions(): array
    {
        return [
            'suspend' => ['post', 'admin.accounts.suspend'],
            'delete' => ['delete', 'admin.accounts.destroy'],
        ];
    }

    #[DataProvider('protectedAccountActions')]
    public function test_admins_cannot_suspend_or_delete_admins_or_themselves(string $method, string $route)
    {
        $admin = User::factory()->admin()->create();
        $otherAdmin = User::factory()->admin()->create();

        foreach ([$admin, $otherAdmin] as $account) {
            $this->actingAs($admin)->{$method}(route($route, $account))->assertForbidden();

            $this->assertSame(AccountStatus::Active, $account->fresh()->status);
        }

        $this->assertDatabaseCount('tasks', 0);
        Queue::assertNothingPushed();
    }

    public function test_accounts_that_are_still_being_set_up_cannot_be_suspended_or_deleted()
    {
        $admin = User::factory()->admin()->create();
        $account = User::factory()->create(['status' => AccountStatus::Pending]);

        $this->actingAs($admin)
            ->post(route('admin.accounts.suspend', $account))
            ->assertSessionHasErrors('account');

        $this->actingAs($admin)
            ->delete(route('admin.accounts.destroy', $account))
            ->assertSessionHasErrors('account');

        $this->assertSame(AccountStatus::Pending, $account->fresh()->status);
        Queue::assertNothingPushed();
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function validAccountData(array $overrides = []): array
    {
        return [
            'username' => 'budi',
            'name' => 'Budi Santoso',
            'email' => 'budi@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
            ...$overrides,
        ];
    }

    private function assertQueuedTask(string $action, User $account): void
    {
        $task = Task::query()->sole();

        $this->assertSame($action, $task->action);
        $this->assertSame(['username' => $account->username], $task->payload);
        $this->assertSame($account->id, $task->user_id);

        Queue::assertPushed(RunAgentTask::class, fn (RunAgentTask $job) => $job->task->is($task));
    }
}
