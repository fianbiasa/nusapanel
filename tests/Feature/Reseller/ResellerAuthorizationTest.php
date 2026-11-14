<?php

namespace Tests\Feature\Reseller;

use App\Enums\AccountStatus;
use App\Models\DnsTemplateRecord;
use App\Models\IpAddress;
use App\Models\Package;
use App\Models\ServerBackup;
use App\Models\User;
use App\Services\ImpersonationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A reseller manages only the hosting accounts and packages it owns. These
 * tests walk every registered route, so a route added later is covered
 * without changing them; a reseller route with a parameter they do not
 * know fails them until it is covered.
 */
class ResellerAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_every_reseller_route_refuses_what_the_reseller_does_not_own()
    {
        $reseller = User::factory()->reseller()->create();
        $otherReseller = User::factory()->reseller()->create();
        $admin = User::factory()->admin()->create();

        $otherPackage = Package::factory()->for($otherReseller, 'owner')->create();
        $adminPackage = Package::factory()->for($admin, 'owner')->create();
        $otherAccount = User::factory()->for($otherReseller, 'owner')->create(['package_id' => $otherPackage->id]);
        $adminAccount = User::factory()->for($admin, 'owner')->create(['package_id' => $adminPackage->id]);
        $otherAddress = IpAddress::factory()->create();
        $otherAddress->forceFill(['owner_id' => $otherReseller->id])->save();
        $adminAddress = IpAddress::factory()->create();
        $otherTemplateRecord = DnsTemplateRecord::factory()->create();
        $otherTemplateRecord->forceFill(['owner_id' => $otherReseller->id])->save();
        $adminTemplateRecord = DnsTemplateRecord::factory()->create();

        /** @var array<string, list<Model>> $foreign */
        $foreign = [
            'account' => [$otherAccount, $adminAccount, $otherReseller, $admin],
            'package' => [$otherPackage, $adminPackage],
            'ipAddress' => [$otherAddress, $adminAddress],
            'dnsTemplateRecord' => [$otherTemplateRecord, $adminTemplateRecord],
            'serverBackup' => [
                ServerBackup::factory()->for($otherAccount, 'account')->create(),
                ServerBackup::factory()->for($adminAccount, 'account')->create(),
                ServerBackup::factory()->create(['user_id' => null, 'owner_id' => $otherReseller->id, 'username' => 'gone']),
            ],
        ];

        $checked = 0;

        foreach ($this->routesNamed('reseller.') as $route) {
            $parameters = $route->parameterNames();

            if ($parameters === []) {
                continue;
            }

            $this->assertCount(1, $parameters, "Route {$route->getName()} has more than one parameter; cover it here.");
            $this->assertArrayHasKey($parameters[0], $foreign, "Route {$route->getName()} has a parameter [{$parameters[0]}] this test does not cover.");

            foreach ($foreign[$parameters[0]] as $model) {
                $this->actingAs($reseller)
                    ->call($this->methodOf($route), route($route->getName(), [$parameters[0] => $model->getKey()]), $this->anyPayload($otherPackage))
                    ->assertNotFound();

                $checked++;
            }
        }

        // Six account routes against four accounts, three package routes
        // against two packages, one address route against two addresses,
        // two DNS template routes against two records, four backup routes
        // against three backups (one of a deleted account).
        $this->assertSame(48, $checked);

        // Nothing changed hands, was queued, removed, or signed in as.
        $this->assertSame(AccountStatus::Active, $otherAccount->fresh()->status);
        $this->assertSame(AccountStatus::Active, $adminAccount->fresh()->status);
        $this->assertSame($otherPackage->id, $otherAccount->fresh()->package_id);
        $this->assertModelExists($otherPackage);
        $this->assertModelExists($adminPackage);
        $this->assertSame($otherPackage->name, $otherPackage->fresh()->name);
        $this->assertFalse($otherAddress->fresh()->dedicated);
        $this->assertFalse($adminAddress->fresh()->dedicated);
        $this->assertModelExists($otherTemplateRecord);
        $this->assertModelExists($adminTemplateRecord);
        $this->assertDatabaseCount('server_backups', 3);
        $this->assertDatabaseCount('tasks', 0);
        $this->assertAuthenticatedAs($reseller);
        $this->assertFalse(session()->has(ImpersonationService::SESSION_KEY));
    }

    public function test_the_routes_of_administrators_and_hosting_accounts_are_closed_to_resellers()
    {
        $reseller = User::factory()->reseller()->create();
        $checked = 0;

        foreach ([...$this->routesNamed('admin.'), ...$this->hostingRoutes()] as $route) {
            $status = $this->actingAs($reseller)
                ->call($this->methodOf($route), $this->urlWithAnyParameters($route))
                ->getStatusCode();

            $this->assertContains($status, [403, 404], "Route {$route->getName()} answered a reseller with {$status}.");
            $checked++;
        }

        $this->assertGreaterThan(100, $checked);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_reseller_routes_are_closed_to_hosting_accounts_and_administrators()
    {
        $reseller = User::factory()->reseller()->create();
        $package = Package::factory()->for($reseller, 'owner')->create();
        $account = User::factory()->for($reseller, 'owner')->create(['package_id' => $package->id]);
        $address = IpAddress::factory()->create();
        $address->forceFill(['owner_id' => $reseller->id])->save();
        $templateRecord = DnsTemplateRecord::factory()->create();
        $templateRecord->forceFill(['owner_id' => $reseller->id])->save();
        $models = [
            'account' => $account,
            'package' => $package,
            'ipAddress' => $address,
            'dnsTemplateRecord' => $templateRecord,
            'serverBackup' => ServerBackup::factory()->for($account, 'account')->create(),
        ];

        foreach ([User::factory()->create(), User::factory()->admin()->create()] as $outsider) {
            foreach ($this->routesNamed('reseller.') as $route) {
                $parameters = [];

                foreach ($route->parameterNames() as $name) {
                    $parameters[$name] = $models[$name]->getKey();
                }

                $this->actingAs($outsider)
                    ->call($this->methodOf($route), route($route->getName(), $parameters))
                    ->assertForbidden();
            }
        }

        $this->assertSame(AccountStatus::Active, $account->fresh()->status);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_guests_are_sent_to_the_login_page()
    {
        $this->get(route('reseller.accounts.index'))->assertRedirect(route('login'));
        $this->post(route('reseller.packages.store'))->assertRedirect(route('login'));
    }

    /**
     * @return list<Route>
     */
    private function routesNamed(string $prefix): array
    {
        return array_values(array_filter(
            RouteFacade::getRoutes()->getRoutes(),
            fn (Route $route): bool => Str::startsWith((string) $route->getName(), $prefix),
        ));
    }

    /**
     * The routes only hosting accounts use: those behind the "role:user"
     * middleware.
     *
     * @return list<Route>
     */
    private function hostingRoutes(): array
    {
        // The panel's routes: the API's answer a session with 401 whatever
        // the role, and its role checks for keys are in tests/Feature/Api.
        return array_values(array_filter(
            RouteFacade::getRoutes()->getRoutes(),
            fn (Route $route): bool => in_array('role:user', $route->gatherMiddleware(), true)
                && ! str_starts_with((string) $route->getName(), 'api.'),
        ));
    }

    private function methodOf(Route $route): string
    {
        return collect($route->methods())->reject(fn (string $method): bool => $method === 'HEAD')->first();
    }

    private function urlWithAnyParameters(Route $route): string
    {
        $parameters = array_fill_keys($route->parameterNames(), 1);

        if (isset($parameters['unit'])) {
            $parameters['unit'] = 'nginx.service';
            $parameters['action'] = 'restart';
        }

        return route($route->getName() ?? '', $parameters);
    }

    /**
     * A payload that would pass validation, so only authorization can refuse.
     *
     * @return array<string, mixed>
     */
    private function anyPayload(Package $package): array
    {
        return [
            'name' => 'Renamed',
            'description' => null,
            ...array_fill_keys(Package::LIMITS, 1),
            'ssl' => true,
            'package_id' => $package->id,
            'reason' => 'Testing',
            'dedicated' => true,
            'ipv4_address_id' => null,
            'ipv6_address_id' => null,
            'type' => 'TXT',
            'content' => 'renamed',
            'ttl' => 3600,
        ];
    }
}
