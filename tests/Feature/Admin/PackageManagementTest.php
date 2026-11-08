<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PackageManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admins_can_list_packages_by_name_with_the_number_of_accounts_on_each()
    {
        $admin = User::factory()->admin()->create();
        $pro = Package::factory()->unlimited()->withoutSsl()->create(['name' => 'Pro']);
        $basic = Package::factory()->create(['name' => 'Basic', 'description' => 'Small sites', 'domains' => 3, 'disk_mb' => 1024]);
        User::factory()->count(2)->create(['package_id' => $basic->id]);

        $this->actingAs($admin)
            ->get(route('admin.packages.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/packages/index')
                ->has('packages', 2)
                ->has('packages.0', fn (Assert $row) => $row
                    ->where('id', $basic->id)
                    ->where('name', 'Basic')
                    ->where('description', 'Small sites')
                    ->where('limits.domains', 3)
                    ->where('limits.disk_mb', 1024)
                    ->has('limits', 8)
                    ->where('ssl', true)
                    ->where('accounts_count', 2))
                ->where('packages.1.id', $pro->id)
                ->where('packages.1.limits.domains', null)
                ->where('packages.1.ssl', false)
                ->where('packages.1.accounts_count', 0));
    }

    public function test_admins_can_see_the_form_for_a_package()
    {
        $admin = User::factory()->admin()->create();
        $package = Package::factory()->create(['name' => 'Basic', 'domains' => 3]);
        User::factory()->create(['package_id' => $package->id]);

        $this->actingAs($admin)->get(route('admin.packages.create'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/packages/create'));

        $this->actingAs($admin)
            ->get(route('admin.packages.edit', $package))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/packages/edit')
                ->where('package.name', 'Basic')
                ->where('package.limits.domains', 3)
                ->where('package.accounts_count', 1));
    }

    public function test_admins_can_create_a_package_where_empty_limits_are_unlimited()
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.packages.store'), $this->validPackageData([
                'bandwidth_mb' => '',
                'cron_jobs' => '',
                'ssl' => '0',
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.packages.index'));

        $package = Package::query()->sole();

        $this->assertSame('Basic', $package->name);
        $this->assertSame('For small websites', $package->description);
        $this->assertSame($admin->id, $package->owner_id);
        $this->assertSame(1024, $package->disk_mb);
        $this->assertSame(3, $package->domains);
        $this->assertSame(0, $package->ftp_accounts);
        $this->assertNull($package->bandwidth_mb);
        $this->assertNull($package->cron_jobs);
        $this->assertFalse($package->ssl);

        $this->assertDatabaseHas(AuditLog::class, [
            'action' => 'package.created',
            'actor_id' => $admin->id,
            'subject_id' => $package->id,
        ]);
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function invalidPackageData(): array
    {
        return [
            'missing name' => [['name' => ''], 'name'],
            'name too long' => [['name' => str_repeat('a', 65)], 'name'],
            'description too long' => [['description' => str_repeat('a', 256)], 'description'],
            'negative limit' => [['domains' => '-1'], 'domains'],
            'fractional limit' => [['disk_mb' => '1.5'], 'disk_mb'],
            'limit too large' => [['bandwidth_mb' => '1000000001'], 'bandwidth_mb'],
            'ssl not a boolean' => [['ssl' => 'yes'], 'ssl'],
        ];
    }

    /**
     * @param  array<string, string>  $overrides
     */
    #[DataProvider('invalidPackageData')]
    public function test_invalid_packages_are_rejected(array $overrides, string $field)
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.packages.store'), $this->validPackageData($overrides))
            ->assertSessionHasErrors($field);

        $this->assertDatabaseCount('packages', 0);
    }

    public function test_every_limit_must_be_sent_so_a_missing_one_is_never_read_as_unlimited()
    {
        $data = $this->validPackageData();
        unset($data['databases']);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.packages.store'), $data)
            ->assertSessionHasErrors('databases');

        $this->assertDatabaseCount('packages', 0);
    }

    public function test_package_names_must_be_unique()
    {
        Package::factory()->create(['name' => 'Basic']);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.packages.store'), $this->validPackageData())
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('packages', 1);
    }

    public function test_admins_can_change_a_package_and_the_audit_log_records_what_changed()
    {
        $admin = User::factory()->admin()->create();
        $package = Package::factory()->create([
            'name' => 'Basic',
            'description' => 'For small websites',
            'disk_mb' => 1024,
            'bandwidth_mb' => 10240,
            'domains' => 3,
            'subdomains' => 10,
            'email_accounts' => 10,
            'databases' => 5,
            'ftp_accounts' => 0,
            'cron_jobs' => 5,
        ]);

        $this->actingAs($admin)
            ->put(route('admin.packages.update', $package), $this->validPackageData(['domains' => '5', 'disk_mb' => '']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.packages.index'));

        $package->refresh();

        $this->assertSame('Basic', $package->name);
        $this->assertSame(5, $package->domains);
        $this->assertNull($package->disk_mb);

        $log = AuditLog::query()->where('action', 'package.updated')->sole();

        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame((string) $package->id, (string) $log->subject_id);
        $this->assertEquals([
            'name' => 'Basic',
            'from' => ['disk_mb' => 1024, 'domains' => 3],
            'to' => ['disk_mb' => null, 'domains' => 5],
        ], $log->changes);
    }

    public function test_saving_a_package_unchanged_records_nothing()
    {
        $package = Package::factory()->create([
            'name' => 'Basic',
            'description' => 'For small websites',
            'disk_mb' => 1024,
            'bandwidth_mb' => 10240,
            'domains' => 3,
            'subdomains' => 10,
            'email_accounts' => 10,
            'databases' => 5,
            'ftp_accounts' => 0,
            'cron_jobs' => 5,
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('admin.packages.update', $package), $this->validPackageData())
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing(AuditLog::class, ['action' => 'package.updated']);
    }

    public function test_a_package_may_keep_its_own_name_but_not_take_another_packages_name()
    {
        $admin = User::factory()->admin()->create();
        $package = Package::factory()->create(['name' => 'Basic']);
        Package::factory()->create(['name' => 'Pro']);

        $this->actingAs($admin)
            ->put(route('admin.packages.update', $package), $this->validPackageData(['name' => 'Basic']))
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->put(route('admin.packages.update', $package), $this->validPackageData(['name' => 'Pro']))
            ->assertSessionHasErrors('name');

        $this->assertSame('Basic', $package->fresh()->name);
    }

    public function test_admins_can_delete_a_package_no_account_uses()
    {
        $admin = User::factory()->admin()->create();
        $package = Package::factory()->create(['name' => 'Basic']);

        $this->actingAs($admin)
            ->from(route('admin.packages.index'))
            ->delete(route('admin.packages.destroy', $package))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.packages.index'));

        $this->assertModelMissing($package);
        $this->assertDatabaseHas(AuditLog::class, [
            'action' => 'package.deleted',
            'actor_id' => $admin->id,
            'subject_id' => $package->id,
        ]);
    }

    public function test_a_package_that_accounts_use_cannot_be_deleted()
    {
        $package = Package::factory()->create();
        User::factory()->count(2)->create(['package_id' => $package->id]);

        $this->actingAs(User::factory()->admin()->create())
            ->delete(route('admin.packages.destroy', $package))
            ->assertSessionHasErrors(['package' => 'This package is still used by 2 account(s). Move them to another package first.']);

        $this->assertModelExists($package);
        $this->assertDatabaseMissing(AuditLog::class, ['action' => 'package.deleted']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function packageRoutes(): array
    {
        return [
            'index' => ['get', 'admin.packages.index'],
            'create' => ['get', 'admin.packages.create'],
            'store' => ['post', 'admin.packages.store'],
            'edit' => ['get', 'admin.packages.edit'],
            'update' => ['put', 'admin.packages.update'],
            'destroy' => ['delete', 'admin.packages.destroy'],
        ];
    }

    #[DataProvider('packageRoutes')]
    public function test_users_and_resellers_cannot_manage_packages(string $method, string $route)
    {
        $package = Package::factory()->create(['name' => 'Basic']);
        $parameters = in_array($route, ['admin.packages.index', 'admin.packages.create', 'admin.packages.store'], true) ? [] : $package;

        foreach ([User::factory()->create(), User::factory()->reseller()->create()] as $actor) {
            $this->actingAs($actor)
                ->{$method}(route($route, $parameters), $this->validPackageData(['name' => 'Hacked']))
                ->assertForbidden();
        }

        $this->assertModelExists($package);
        $this->assertSame('Basic', $package->fresh()->name);
        $this->assertDatabaseCount('packages', 1);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function validPackageData(array $overrides = []): array
    {
        return [
            'name' => 'Basic',
            'description' => 'For small websites',
            'disk_mb' => '1024',
            'bandwidth_mb' => '10240',
            'domains' => '3',
            'subdomains' => '10',
            'email_accounts' => '10',
            'databases' => '5',
            'ftp_accounts' => '0',
            'cron_jobs' => '5',
            'ssl' => '1',
            ...$overrides,
        ];
    }
}
