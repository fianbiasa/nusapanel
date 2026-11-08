<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountStatus;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Package;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccountPackageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_the_account_pages_offer_the_packages_by_name()
    {
        $admin = User::factory()->admin()->create();
        $pro = Package::factory()->create(['name' => 'Pro']);
        $basic = Package::factory()->create(['name' => 'Basic']);
        User::factory()->create(['package_id' => $basic->id]);

        $this->actingAs($admin)
            ->get(route('admin.accounts.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('packages', [
                    ['id' => $basic->id, 'name' => 'Basic'],
                    ['id' => $pro->id, 'name' => 'Pro'],
                ]));

        $this->actingAs($admin)
            ->get(route('admin.accounts.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('packages', 2)
                ->where('accounts.data.0.package_id', $basic->id)
                ->where('accounts.data.0.package', 'Basic')
                ->where('accounts.data.1.package_id', null)
                ->where('accounts.data.1.package', null));
    }

    public function test_admins_can_create_an_account_on_a_package()
    {
        $admin = User::factory()->admin()->create();
        $package = Package::factory()->create(['name' => 'Basic']);

        $this->actingAs($admin)
            ->post(route('admin.accounts.store'), $this->validAccountData(['package_id' => (string) $package->id]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.accounts.index'));

        $account = User::query()->where('username', 'budi')->sole();

        $this->assertSame($package->id, $account->package_id);
        $this->assertSame('Basic', AuditLog::query()->where('action', 'account.created')->sole()->changes['package']);
    }

    public function test_accounts_created_without_a_package_have_none()
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.accounts.store'), $this->validAccountData(['package_id' => '']))
            ->assertSessionHasNoErrors();

        $this->assertNull(User::query()->where('username', 'budi')->sole()->package_id);
    }

    public function test_an_account_is_not_created_on_a_package_that_does_not_exist()
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.accounts.store'), $this->validAccountData(['package_id' => '999']))
            ->assertSessionHasErrors('package_id');

        $this->assertDatabaseMissing('users', ['username' => 'budi']);
        Queue::assertNothingPushed();
    }

    public function test_an_account_is_not_created_when_its_package_has_no_room_for_the_primary_domain()
    {
        $package = Package::factory()->create(['domains' => 0]);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.accounts.store'), $this->validAccountData([
                'package_id' => (string) $package->id,
                'domain' => 'example.com',
            ]))
            ->assertSessionHasErrors(['domain' => "This account's package allows 0 domains."]);

        $this->assertDatabaseMissing('users', ['username' => 'budi']);
        $this->assertDatabaseCount('domains', 0);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_a_primary_domain_within_the_package_limit_is_added()
    {
        $package = Package::factory()->create(['domains' => 1]);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.accounts.store'), $this->validAccountData([
                'package_id' => (string) $package->id,
                'domain' => 'example.com',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('example.com', Domain::query()->sole()->name);
    }

    public function test_admins_can_move_an_account_to_another_package_and_its_disk_quota_follows()
    {
        $admin = User::factory()->admin()->create();
        $basic = Package::factory()->create(['name' => 'Basic']);
        $pro = Package::factory()->create(['name' => 'Pro', 'disk_mb' => 5120, 'inodes' => 200000]);
        $account = User::factory()->create(['username' => 'budi', 'package_id' => $basic->id]);

        $this->actingAs($admin)
            ->from(route('admin.accounts.index'))
            ->put(route('admin.accounts.package.update', $account), ['package_id' => (string) $pro->id])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.accounts.index'));

        $this->assertSame($pro->id, $account->fresh()->package_id);

        $log = AuditLog::query()->where('action', 'account.package_changed')->sole();

        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame((string) $account->id, (string) $log->subject_id);
        $this->assertSame(['username' => 'budi', 'from' => 'Basic', 'to' => 'Pro'], $log->changes);

        $task = Task::query()->sole();

        $this->assertSame('account.set_quota', $task->action);
        $this->assertSame(['username' => 'budi', 'disk_mb' => 5120, 'inodes' => 200000], $task->payload);
        $this->assertSame($account->id, $task->user_id);
        $this->assertSame($admin->id, $task->actor_id);
    }

    public function test_admins_can_take_an_account_off_packages_which_lifts_its_disk_quota()
    {
        $package = Package::factory()->create(['name' => 'Basic']);
        $account = User::factory()->suspended()->create(['username' => 'budi', 'package_id' => $package->id]);

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('admin.accounts.package.update', $account), ['package_id' => ''])
            ->assertSessionHasNoErrors();

        $this->assertNull($account->fresh()->package_id);
        $this->assertSame(['from' => 'Basic', 'to' => null], array_intersect_key(
            AuditLog::query()->where('action', 'account.package_changed')->sole()->changes,
            ['from' => true, 'to' => true],
        ));
        $this->assertSame(
            ['username' => 'budi', 'disk_mb' => 0, 'inodes' => 0],
            Task::query()->where('action', 'account.set_quota')->sole()->payload,
        );
    }

    public function test_an_account_that_is_still_being_created_gets_its_disk_quota_once_it_exists()
    {
        $package = Package::factory()->create();
        $account = User::factory()->create(['status' => AccountStatus::Pending]);

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('admin.accounts.package.update', $account), ['package_id' => (string) $package->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($package->id, $account->fresh()->package_id);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_an_account_can_be_moved_to_a_package_it_already_uses_more_than()
    {
        $small = Package::factory()->create(['domains' => 1]);
        $account = User::factory()->create();
        Domain::factory()->count(2)->for($account, 'account')->create();

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('admin.accounts.package.update', $account), ['package_id' => (string) $small->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($small->id, $account->fresh()->package_id);
        $this->assertSame(2, $account->domains()->count());
    }

    public function test_the_package_must_be_sent_and_exist()
    {
        $account = User::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('admin.accounts.package.update', $account), [])
            ->assertSessionHasErrors('package_id');

        $this->actingAs($admin)
            ->put(route('admin.accounts.package.update', $account), ['package_id' => '999'])
            ->assertSessionHasErrors('package_id');

        $this->assertNull($account->fresh()->package_id);
    }

    public function test_accounts_that_are_being_deleted_cannot_change_package()
    {
        $package = Package::factory()->create();
        $account = User::factory()->create(['status' => AccountStatus::Deleting]);

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('admin.accounts.package.update', $account), ['package_id' => (string) $package->id])
            ->assertSessionHasErrors(['account' => 'This account is being deleted.']);

        $this->assertNull($account->fresh()->package_id);
    }

    public function test_admins_and_resellers_cannot_be_put_on_a_package()
    {
        $admin = User::factory()->admin()->create();
        $package = Package::factory()->create();

        foreach ([$admin, User::factory()->admin()->create(), User::factory()->reseller()->create()] as $account) {
            $this->actingAs($admin)
                ->put(route('admin.accounts.package.update', $account), ['package_id' => (string) $package->id])
                ->assertForbidden();

            $this->assertNull($account->fresh()->package_id);
        }
    }

    public function test_unknown_accounts_are_not_found()
    {
        $this->actingAs(User::factory()->admin()->create())
            ->put(route('admin.accounts.package.update', 999), ['package_id' => ''])
            ->assertNotFound();
    }

    public function test_users_and_resellers_cannot_change_packages()
    {
        $package = Package::factory()->create();
        $account = User::factory()->create();

        foreach ([$account, User::factory()->reseller()->create()] as $actor) {
            $this->actingAs($actor)
                ->put(route('admin.accounts.package.update', $account), ['package_id' => (string) $package->id])
                ->assertForbidden();
        }

        $this->assertNull($account->fresh()->package_id);
        $this->assertDatabaseMissing(AuditLog::class, ['action' => 'account.package_changed']);
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
}
