<?php

namespace Tests\Feature\Reseller;

use App\Models\Package;
use App\Models\ResellerPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ResellerPackageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_a_reseller_lists_only_its_own_packages()
    {
        $reseller = $this->reseller();
        $own = Package::factory()->for($reseller, 'owner')->create();
        Package::factory()->for(User::factory()->admin(), 'owner')->create();
        Package::factory()->for(User::factory()->reseller(), 'owner')->create();

        $this->actingAs($reseller)
            ->get(route('reseller.packages.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('scope', 'reseller')
                ->has('packages', 1)
                ->where('packages.0.id', $own->id));
    }

    public function test_a_reseller_creates_a_package_within_its_reseller_package()
    {
        $reseller = $this->reseller();

        $this->actingAs($reseller)
            ->post(route('reseller.packages.store'), $this->packageData(['name' => 'Basic']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('reseller.packages.index'));

        $this->assertDatabaseHas('packages', ['name' => 'Basic', 'owner_id' => $reseller->id, 'disk_mb' => 1000]);
    }

    public function test_a_package_of_a_reseller_may_not_go_over_its_reseller_package()
    {
        $reseller = $this->reseller(['disk_mb' => 1500, 'ssl' => false]);

        $this->actingAs($reseller)
            ->post(route('reseller.packages.store'), $this->packageData(['disk_mb' => 2000, 'domains' => null, 'ssl' => true]))
            ->assertSessionHasErrors([
                'disk_mb' => 'At most 1500, the limit of your reseller package.',
                'domains' => 'At most 30, the limit of your reseller package.',
                'ssl' => 'Your reseller package does not include SSL.',
            ]);

        $this->assertDatabaseCount('packages', 0);
    }

    public function test_package_names_are_unique_per_owner()
    {
        $reseller = $this->reseller();
        Package::factory()->for(User::factory()->admin(), 'owner')->create(['name' => 'Basic']);
        Package::factory()->for($reseller, 'owner')->create(['name' => 'Pro']);

        $this->actingAs($reseller)
            ->post(route('reseller.packages.store'), $this->packageData(['name' => 'Basic']))
            ->assertSessionHasNoErrors();

        $this->actingAs($reseller)
            ->post(route('reseller.packages.store'), $this->packageData(['name' => 'Pro']))
            ->assertSessionHasErrors('name');
    }

    public function test_raising_a_package_in_use_may_not_give_out_more_than_the_reseller_package()
    {
        $reseller = $this->reseller(['disk_mb' => 1500]);
        $package = Package::factory()->for($reseller, 'owner')->create($this->limits(['disk_mb' => 500]));
        User::factory()->count(2)->for($reseller, 'owner')->create(['package_id' => $package->id]);

        // Two accounts at 800 MB each would be 1600 MB.
        $this->actingAs($reseller)
            ->put(route('reseller.packages.update', $package), $this->packageData(['name' => $package->name, 'disk_mb' => 800]))
            ->assertSessionHasErrors(['disk_mb' => 'With the 2 account(s) on this package, the reseller would give out 1600, more than the 1500 of its reseller package.']);
        $this->assertSame(500, $package->fresh()->disk_mb);

        $this->actingAs($reseller)
            ->put(route('reseller.packages.update', $package), $this->packageData(['name' => $package->name, 'disk_mb' => 750]))
            ->assertSessionHasNoErrors();
        $this->assertSame(750, $package->fresh()->disk_mb);
    }

    /**
     * @param  array<string, mixed>  $limits
     */
    private function reseller(array $limits = []): User
    {
        return User::factory()->reseller()->create([
            'reseller_package_id' => ResellerPackage::factory()->create($limits)->id,
        ]);
    }

    /**
     * Limits that fit the factory's reseller package.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function limits(array $overrides = []): array
    {
        return [
            'disk_mb' => 1000,
            'inodes' => 50000,
            'bandwidth_mb' => 10240,
            'domains' => 3,
            'subdomains' => 10,
            'pointers' => 5,
            'email_accounts' => 10,
            'email_forwarders' => 10,
            'databases' => 5,
            'ftp_accounts' => 5,
            'cron_jobs' => 5,
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function packageData(array $overrides = []): array
    {
        return [
            'name' => 'Basic',
            'description' => null,
            ...$this->limits(),
            'ssl' => true,
            ...$overrides,
        ];
    }
}
