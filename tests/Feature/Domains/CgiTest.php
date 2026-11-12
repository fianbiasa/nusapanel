<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Package;
use App\Models\ResellerPackage;
use App\Models\Task;
use App\Models\User;
use App\Services\AccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CgiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Package $package;

    private User $account;

    private Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->admin = User::factory()->admin()->create();
        $this->package = Package::factory()->for($this->admin, 'owner')->create(['cgi' => true]);
        $this->account = User::factory()->create(['username' => 'budi', 'owner_id' => $this->admin->id, 'package_id' => $this->package->id]);
        $this->domain = Domain::factory()->for($this->account, 'account')->create(['name' => 'example.com']);
    }

    public function test_cgi_is_turned_on_and_off_for_the_website_and_its_aliases()
    {
        Domain::factory()->pointerOf($this->domain)->create(['name' => 'example.net']);

        $this->actingAs($this->account)
            ->put(route('domains.cgi.update', $this->domain), ['cgi_enabled' => true])
            ->assertSessionHasNoErrors();

        $this->assertTrue($this->domain->fresh()->cgi_enabled);
        $payloads = Task::query()->orderBy('id')->pluck('payload');
        $this->assertSame(['example.com', 'example.net'], $payloads->pluck('domain')->all());
        $this->assertTrue($payloads[0]['cgi']);
        $this->assertTrue($payloads[1]['cgi']);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'domain.cgi_enabled']);

        $this->actingAs($this->account)
            ->put(route('domains.cgi.update', $this->domain), ['cgi_enabled' => false])
            ->assertSessionHasNoErrors();

        $this->assertFalse($this->domain->fresh()->cgi_enabled);
        $this->assertArrayNotHasKey('cgi', Task::query()->latest('id')->first()->payload);
    }

    public function test_cgi_needs_a_package_that_includes_it()
    {
        $this->package->forceFill(['cgi' => false])->save();

        $this->actingAs($this->account)
            ->get(route('domains.web.show', $this->domain))
            ->assertInertia(fn (Assert $page) => $page->where('cgi', ['enabled' => false, 'allowed' => false]));

        $this->actingAs($this->account)
            ->put(route('domains.cgi.update', $this->domain), ['cgi_enabled' => true])
            ->assertSessionHasErrors(['cgi_enabled' => 'CGI is not included in your package.']);

        $this->assertFalse($this->domain->fresh()->cgi_enabled);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_cgi_stops_on_every_website_when_the_package_drops_it_and_comes_back_with_it()
    {
        $this->domain->forceFill(['cgi_enabled' => true])->save();
        $other = Domain::factory()->for($this->account, 'account')->create(['name' => 'example.org']);

        $this->actingAs($this->admin)
            ->put(route('admin.packages.update', $this->package), [...$this->packageFields(), 'cgi' => '0'])
            ->assertSessionHasNoErrors();

        // Only the website that turned CGI on is applied again, without it.
        $task = Task::query()->sole();
        $this->assertSame('example.com', $task->payload['domain']);
        $this->assertArrayNotHasKey('cgi', $task->payload);
        $this->assertTrue($this->domain->fresh()->cgi_enabled);
        $this->assertSame(DomainStatus::Active, $other->fresh()->status);

        $this->actingAs($this->admin)
            ->put(route('admin.packages.update', $this->package), [...$this->packageFields(), 'cgi' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Task::query()->latest('id')->first()->payload['cgi']);
    }

    public function test_cgi_follows_the_account_to_another_package_or_owner()
    {
        $this->domain->forceFill(['cgi_enabled' => true])->save();
        $withoutCgi = Package::factory()->for($this->admin, 'owner')->create(['cgi' => false]);
        $accounts = app(AccountService::class);

        $accounts->changePackage($this->account, $withoutCgi, $this->admin);
        $this->assertArrayNotHasKey('cgi', $this->applyTasks()->last());

        $accounts->changePackage($this->account, $this->package, $this->admin);
        $this->assertTrue($this->applyTasks()->last()['cgi']);

        // A reseller whose own package has no CGI cannot give it either.
        $reseller = User::factory()->reseller()->create(['reseller_package_id' => ResellerPackage::factory()->create(['cgi' => false])->id]);
        $resellerPackage = Package::factory()->for($reseller, 'owner')->create(['cgi' => true]);

        $accounts->transfer($this->account, $reseller, $resellerPackage, $this->admin);
        $this->assertArrayNotHasKey('cgi', $this->applyTasks()->last());

        $accounts->changeResellerPackage($reseller, ResellerPackage::factory()->create(['cgi' => true]), $this->admin);
        $this->assertTrue($this->applyTasks()->last()['cgi']);
    }

    /**
     * The payloads of the queued web.apply_domain tasks, oldest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function applyTasks(): Collection
    {
        return Task::query()->where('action', 'web.apply_domain')->orderBy('id')->pluck('payload');
    }

    /**
     * The package as it is, for an update form.
     *
     * @return array<string, mixed>
     */
    private function packageFields(): array
    {
        return [
            'name' => $this->package->name,
            'description' => $this->package->description,
            ...collect(Package::LIMITS)->mapWithKeys(fn (string $key) => [$key => $this->package->limit($key)])->all(),
            'ssl' => $this->package->ssl ? '1' : '0',
        ];
    }
}
