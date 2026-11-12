<?php

namespace Tests\Feature\Reseller;

use App\Enums\AccountStatus;
use App\Enums\Role;
use App\Models\AccountUsage;
use App\Models\Package;
use App\Models\ResellerPackage;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ResellerAccountManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_a_reseller_lists_only_its_own_hosting_accounts()
    {
        $reseller = User::factory()->reseller()->create();
        $own = User::factory()->for($reseller, 'owner')->create(['username' => 'budi']);
        User::factory()->for(User::factory()->reseller(), 'owner')->create(['username' => 'other']);
        User::factory()->create(['username' => 'adminowned']);

        $this->actingAs($reseller)
            ->get(route('reseller.accounts.index', ['role' => 'admin', 'owner' => 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/accounts/index')
                ->where('scope', 'reseller')
                ->has('accounts.data', 1)
                ->where('accounts.data.0.id', $own->id)
                ->where('packageGroups', null)
                ->where('owners', null)
                ->where('filters.role', '')
                ->where('filters.owner', ''));
    }

    public function test_the_account_list_sorts_by_disk_usage_or_username_and_nothing_else()
    {
        $reseller = User::factory()->reseller()->create();
        $small = User::factory()->for($reseller, 'owner')->create(['username' => 'zaki']);
        $large = User::factory()->for($reseller, 'owner')->create(['username' => 'andi']);
        $unmeasured = User::factory()->for($reseller, 'owner')->create(['username' => 'budi']);
        AccountUsage::factory()->for($small, 'account')->create(['disk_kb' => 1024]);
        AccountUsage::factory()->for($large, 'account')->create(['disk_kb' => 9 * 1024]);

        $this->actingAs($reseller)
            ->get(route('reseller.accounts.index', ['sort' => 'disk']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.sort', 'disk')
                ->where('accounts.data.0.id', $large->id)
                ->where('accounts.data.1.id', $small->id)
                ->where('accounts.data.2.id', $unmeasured->id));

        $this->actingAs($reseller)
            ->get(route('reseller.accounts.index', ['sort' => 'username']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('accounts.data.0.username', 'andi')
                ->where('accounts.data.2.username', 'zaki'));

        $this->actingAs($reseller)
            ->get(route('reseller.accounts.index', ['sort' => 'password']))
            ->assertSessionHasErrors('sort');
    }

    public function test_a_reseller_creates_a_hosting_account_on_its_own_package()
    {
        $reseller = $this->reseller();
        $package = Package::factory()->for($reseller, 'owner')->create(['disk_mb' => 1000]);

        $this->actingAs($reseller)
            ->post(route('reseller.accounts.store'), $this->accountData(['package_id' => $package->id]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('reseller.accounts.index'));

        $account = User::query()->where('username', 'budi')->sole();

        $this->assertSame(Role::User, $account->role);
        $this->assertSame(AccountStatus::Pending, $account->status);
        $this->assertSame($reseller->id, $account->owner_id);
        $this->assertSame($package->id, $account->package_id);
        $this->assertSame('account.create', Task::query()->sole()->action);
    }

    public function test_a_reseller_cannot_create_a_reseller_or_an_administrator()
    {
        $reseller = $this->reseller(ResellerPackage::factory()->unlimited()->create());

        foreach (['reseller', 'admin'] as $role) {
            $this->actingAs($reseller)
                ->post(route('reseller.accounts.store'), $this->accountData(['role' => $role]))
                ->assertSessionHasErrors(['role' => 'You cannot create an account of this kind.']);
        }

        $this->assertDatabaseMissing('users', ['username' => 'budi']);
    }

    public function test_a_reseller_cannot_put_an_account_on_a_package_of_someone_else()
    {
        $reseller = $this->reseller(ResellerPackage::factory()->unlimited()->create());
        $adminPackage = Package::factory()->for(User::factory()->admin(), 'owner')->create();
        $otherPackage = Package::factory()->for(User::factory()->reseller(), 'owner')->create();

        foreach ([$adminPackage, $otherPackage] as $package) {
            $this->actingAs($reseller)
                ->post(route('reseller.accounts.store'), $this->accountData(['package_id' => $package->id]))
                ->assertSessionHasErrors(['package_id' => 'Choose one of your packages.']);
        }

        $this->assertDatabaseMissing('users', ['username' => 'budi']);
    }

    public function test_the_reseller_package_limits_the_number_of_accounts_even_when_overselling()
    {
        $reseller = $this->reseller(ResellerPackage::factory()->oversold()->create(['accounts' => 1]));
        $package = Package::factory()->for($reseller, 'owner')->create();
        User::factory()->for($reseller, 'owner')->create(['package_id' => $package->id]);

        $this->actingAs($reseller)
            ->post(route('reseller.accounts.store'), $this->accountData(['package_id' => $package->id]))
            ->assertSessionHasErrors(['package_id' => "The reseller's package allows only 1 accounts."]);

        $this->assertDatabaseMissing('users', ['username' => 'budi']);
    }

    public function test_without_overselling_the_packages_together_may_not_give_out_more_than_the_reseller_package()
    {
        $reseller = $this->reseller(ResellerPackage::factory()->unlimited()->create(['disk_mb' => 1500]));
        $package = Package::factory()->for($reseller, 'owner')->unlimited()->create(['disk_mb' => 1000]);
        User::factory()->for($reseller, 'owner')->create(['package_id' => $package->id]);

        $this->actingAs($reseller)
            ->post(route('reseller.accounts.store'), $this->accountData(['package_id' => $package->id]))
            ->assertSessionHasErrors(['package_id' => "With this package the reseller's accounts would get 2000 MB of disk space, but the reseller's package allows 1500."]);

        $this->actingAs($reseller)
            ->post(route('reseller.accounts.store'), $this->accountData(['package_id' => null]))
            ->assertSessionHasErrors(['package_id' => "The reseller's package limits MB of disk space, so the account needs a package that limits it too."]);

        $this->assertDatabaseMissing('users', ['username' => 'budi']);
    }

    public function test_with_overselling_the_packages_may_give_out_more_than_the_reseller_package()
    {
        $reseller = $this->reseller(ResellerPackage::factory()->unlimited()->oversold()->create(['disk_mb' => 1500]));
        $package = Package::factory()->for($reseller, 'owner')->unlimited()->create(['disk_mb' => 1000]);
        User::factory()->for($reseller, 'owner')->create(['package_id' => $package->id]);

        $this->actingAs($reseller)
            ->post(route('reseller.accounts.store'), $this->accountData(['package_id' => $package->id]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['username' => 'budi', 'owner_id' => $reseller->id]);
    }

    public function test_a_reseller_moves_its_account_to_another_of_its_packages_within_its_limits()
    {
        $reseller = $this->reseller(ResellerPackage::factory()->unlimited()->create(['disk_mb' => 1500]));
        $small = Package::factory()->for($reseller, 'owner')->unlimited()->create(['disk_mb' => 500]);
        $large = Package::factory()->for($reseller, 'owner')->unlimited()->create(['disk_mb' => 1000]);
        $tooLarge = Package::factory()->for($reseller, 'owner')->unlimited()->create(['disk_mb' => 1600]);
        $account = User::factory()->for($reseller, 'owner')->create(['package_id' => $small->id]);
        User::factory()->for($reseller, 'owner')->create(['package_id' => $small->id]);

        // 500 for the other account plus 1600 is over 1500; 500 plus 1000 is
        // not (the account's own 500 no longer counts once it moves).
        $this->actingAs($reseller)
            ->put(route('reseller.accounts.package.update', $account), ['package_id' => $tooLarge->id])
            ->assertSessionHasErrors('package_id');
        $this->assertSame($small->id, $account->fresh()->package_id);

        $this->actingAs($reseller)
            ->put(route('reseller.accounts.package.update', $account), ['package_id' => $large->id])
            ->assertSessionHasNoErrors();
        $this->assertSame($large->id, $account->fresh()->package_id);
    }

    public function test_a_reseller_suspends_its_account_on_the_server()
    {
        $reseller = $this->reseller();
        $account = User::factory()->for($reseller, 'owner')->create();

        $this->actingAs($reseller)
            ->post(route('reseller.accounts.suspend', $account), ['reason' => 'Unpaid'])
            ->assertSessionHasNoErrors();

        $this->assertSame(AccountStatus::Suspended, $account->fresh()->status);
        $this->assertSame('account.suspend', Task::query()->sole()->action);
    }

    public function test_a_bulk_action_of_a_reseller_only_reaches_its_own_accounts()
    {
        $reseller = $this->reseller();
        $own = User::factory()->for($reseller, 'owner')->create();
        $foreign = User::factory()->for(User::factory()->reseller(), 'owner')->create();

        $this->actingAs($reseller)
            ->post(route('reseller.accounts.bulk'), ['action' => 'suspend', 'ids' => [$own->id, $foreign->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame(AccountStatus::Suspended, $own->fresh()->status);
        $this->assertSame(AccountStatus::Active, $foreign->fresh()->status);
    }

    private function reseller(?ResellerPackage $package = null): User
    {
        return User::factory()->reseller()->create([
            'reseller_package_id' => ($package ?? ResellerPackage::factory()->create())->id,
        ]);
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
}
