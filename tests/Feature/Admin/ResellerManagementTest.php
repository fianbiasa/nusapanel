<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountStatus;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Package;
use App\Models\ResellerPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ResellerManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_admins_create_a_reseller_on_a_reseller_package_that_is_ready_at_once()
    {
        $admin = User::factory()->admin()->create();
        $package = ResellerPackage::factory()->create(['name' => 'Reseller 10']);

        $this->actingAs($admin)
            ->post(route('admin.accounts.store'), $this->accountData([
                'role' => 'reseller',
                'reseller_package_id' => $package->id,
                'domain' => 'example.com',
                'package_id' => Package::factory()->for($admin, 'owner')->create()->id,
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.accounts.index'));

        $reseller = User::query()->where('username', 'budi')->sole();

        $this->assertSame(Role::Reseller, $reseller->role);
        $this->assertSame(AccountStatus::Active, $reseller->status);
        $this->assertSame($admin->id, $reseller->owner_id);
        $this->assertSame($package->id, $reseller->reseller_package_id);
        $this->assertNull($reseller->package_id);
        $this->assertDatabaseCount('domains', 0);
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'account.created', 'subject_id' => $reseller->id, 'actor_id' => $admin->id]);
    }

    public function test_admins_create_another_administrator()
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.accounts.store'), $this->accountData(['role' => 'admin', 'reseller_package_id' => ResellerPackage::factory()->create()->id]))
            ->assertSessionHasNoErrors();

        $created = User::query()->where('username', 'budi')->sole();

        $this->assertSame(Role::Admin, $created->role);
        $this->assertSame(AccountStatus::Active, $created->status);
        $this->assertNull($created->reseller_package_id);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_admins_put_a_reseller_on_another_reseller_package()
    {
        $admin = User::factory()->admin()->create();
        $reseller = User::factory()->reseller()->create(['reseller_package_id' => ResellerPackage::factory()->create()->id]);
        $bigger = ResellerPackage::factory()->create(['name' => 'Bigger']);

        $this->actingAs($admin)
            ->put(route('admin.accounts.reseller-package.update', $reseller), ['reseller_package_id' => $bigger->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($bigger->id, $reseller->fresh()->reseller_package_id);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'account.reseller_package_changed', 'subject_id' => $reseller->id]);
    }

    public function test_suspending_a_reseller_only_blocks_its_sign_in()
    {
        $admin = User::factory()->admin()->create();
        $reseller = User::factory()->reseller()->create();
        $customer = User::factory()->for($reseller, 'owner')->create();

        $this->actingAs($admin)
            ->post(route('admin.accounts.suspend', $reseller))
            ->assertSessionHasNoErrors();

        $this->assertSame(AccountStatus::Suspended, $reseller->fresh()->status);
        $this->assertSame(AccountStatus::Active, $customer->fresh()->status);
        $this->assertDatabaseCount('tasks', 0);

        $this->actingAs($admin)
            ->post(route('admin.accounts.unsuspend', $reseller))
            ->assertSessionHasNoErrors();

        $this->assertSame(AccountStatus::Active, $reseller->fresh()->status);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_a_reseller_that_still_owns_accounts_is_not_deleted()
    {
        $admin = User::factory()->admin()->create();
        $reseller = User::factory()->reseller()->create();
        User::factory()->for($reseller, 'owner')->create();

        $this->actingAs($admin)
            ->delete(route('admin.accounts.destroy', $reseller))
            ->assertSessionHasErrors(['account' => 'This reseller still owns 1 account(s). Move them to another owner or delete them first.']);

        $this->assertModelExists($reseller);
    }

    public function test_deleting_a_reseller_removes_it_and_its_packages_right_away()
    {
        $admin = User::factory()->admin()->create();
        $reseller = User::factory()->reseller()->create();
        $package = Package::factory()->for($reseller, 'owner')->create();

        $this->actingAs($admin)
            ->delete(route('admin.accounts.destroy', $reseller))
            ->assertSessionHasNoErrors();

        $this->assertModelMissing($reseller);
        $this->assertModelMissing($package);
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'account.deleted', 'subject_id' => $reseller->id]);
    }

    public function test_admins_move_an_account_to_a_reseller_on_one_of_its_packages()
    {
        $admin = User::factory()->admin()->create();
        $reseller = User::factory()->reseller()->create(['reseller_package_id' => ResellerPackage::factory()->unlimited()->create()->id]);
        $resellerPackage = Package::factory()->for($reseller, 'owner')->create();
        $account = User::factory()->for($admin, 'owner')->create([
            'package_id' => Package::factory()->for($admin, 'owner')->create()->id,
        ]);

        $this->actingAs($admin)
            ->put(route('admin.accounts.owner.update', $account), ['owner_id' => $reseller->id, 'package_id' => $resellerPackage->id])
            ->assertSessionHasNoErrors();

        $account->refresh();
        $this->assertSame($reseller->id, $account->owner_id);
        $this->assertSame($resellerPackage->id, $account->package_id);
        $this->assertDatabaseHas('tasks', ['action' => 'account.set_quota', 'user_id' => $account->id]);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'account.transferred', 'subject_id' => $account->id]);
    }

    public function test_an_account_moves_only_onto_a_package_of_its_new_owner_within_its_limits()
    {
        $admin = User::factory()->admin()->create();
        $reseller = User::factory()->reseller()->create(['reseller_package_id' => ResellerPackage::factory()->oversold()->create(['accounts' => 1])->id]);
        $resellerPackage = Package::factory()->for($reseller, 'owner')->create();
        $account = User::factory()->for($admin, 'owner')->create();

        $this->actingAs($admin)
            ->put(route('admin.accounts.owner.update', $account), ['owner_id' => $reseller->id, 'package_id' => Package::factory()->for($admin, 'owner')->create()->id])
            ->assertSessionHasErrors(['package_id' => "This package does not belong to {$reseller->username}."]);

        User::factory()->for($reseller, 'owner')->create(['package_id' => $resellerPackage->id]);

        $this->actingAs($admin)
            ->put(route('admin.accounts.owner.update', $account), ['owner_id' => $reseller->id, 'package_id' => $resellerPackage->id])
            ->assertSessionHasErrors(['package_id' => "The reseller's package allows only 1 accounts."]);

        $this->assertSame($admin->id, $account->fresh()->owner_id);
    }

    public function test_admins_manage_reseller_packages()
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.reseller-packages.store'), $this->resellerPackageData(['name' => 'Reseller 10']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.reseller-packages.index'));

        $package = ResellerPackage::query()->sole();
        $this->assertSame(10, $package->accounts);
        $this->assertTrue($package->oversell);

        $this->actingAs($admin)
            ->put(route('admin.reseller-packages.update', $package), $this->resellerPackageData(['name' => 'Reseller 20', 'accounts' => 20]))
            ->assertSessionHasNoErrors();
        $this->assertSame(20, $package->fresh()->accounts);

        $reseller = User::factory()->reseller()->create(['reseller_package_id' => $package->id]);

        $this->actingAs($admin)
            ->delete(route('admin.reseller-packages.destroy', $package))
            ->assertSessionHasErrors(['package' => 'This package is still used by 1 reseller(s). Move them to another package first.']);

        $reseller->forceFill(['reseller_package_id' => null])->save();

        $this->actingAs($admin)
            ->delete(route('admin.reseller-packages.destroy', $package))
            ->assertSessionHasNoErrors();
        $this->assertModelMissing($package);
    }

    public function test_the_account_list_offers_each_account_the_packages_of_its_owner()
    {
        $admin = User::factory()->admin()->create();
        $reseller = User::factory()->reseller()->create();
        $adminPackage = Package::factory()->for($admin, 'owner')->create();
        $resellerPackage = Package::factory()->for($reseller, 'owner')->create();
        User::factory()->for($reseller, 'owner')->create();

        $this->actingAs($admin)
            ->get(route('admin.accounts.index', ['owner' => $reseller->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('scope', 'admin')
                ->has('accounts.data', 1)
                ->where('accounts.data.0.packages_of', (string) $reseller->id)
                ->where('packageGroups.admin.0.id', $adminPackage->id)
                ->where("packageGroups.{$reseller->id}.0.id", $resellerPackage->id)
                ->has('packages', 1));
    }

    public function test_hosting_accounts_of_a_reseller_without_ssl_cannot_get_certificates()
    {
        $reseller = User::factory()->reseller()->create(['reseller_package_id' => ResellerPackage::factory()->create(['ssl' => false])->id]);
        $account = User::factory()->for($reseller, 'owner')->create();
        $domain = Domain::factory()->for($account, 'account')->create();

        $this->actingAs($account)
            ->post(route('domains.ssl.store', $domain))
            ->assertSessionHasErrors(['domain' => 'SSL is not included in your package.']);

        $this->assertDatabaseCount('tasks', 0);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function accountData(array $overrides = []): array
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

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function resellerPackageData(array $overrides = []): array
    {
        return [
            'name' => 'Reseller',
            'description' => null,
            ...array_fill_keys(ResellerPackage::LIMITS, null),
            'accounts' => 10,
            'disk_mb' => 10240,
            'ssl' => true,
            'oversell' => true,
            ...$overrides,
        ];
    }
}
