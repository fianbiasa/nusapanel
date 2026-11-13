<?php

namespace Tests\Feature\Branding;

use App\Models\AuditLog;
use App\Models\ResellerSetting;
use App\Models\Task;
use App\Models\User;
use App\Services\DomainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('local');
        config(['hosting.public_ip' => '203.0.113.10', 'app.url' => 'https://server.example.net:2222']);
    }

    public function test_resellers_brand_the_panel_for_themselves_and_their_accounts()
    {
        $reseller = User::factory()->reseller()->create();
        $account = User::factory()->for($reseller, 'owner')->create();

        $this->actingAs($reseller)
            ->post(route('reseller.branding.update'), [
                'brand_name' => 'Webku Hosting',
                'brand_color' => '#0F766E',
                'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
            ])
            ->assertSessionHasNoErrors();

        $settings = ResellerSetting::query()->sole();
        $this->assertSame(['Webku Hosting', '#0f766e', 'branding/'.$reseller->id.'.png'], [$settings->brand_name, $settings->brand_color, $settings->logo_path]);
        Storage::disk('local')->assertExists('branding/'.$reseller->id.'.png');
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'reseller.branding_updated', 'actor_id' => $reseller->id]);

        foreach ([$reseller, $account] as $user) {
            $this->actingAs($user)
                ->get(route('dashboard'))
                ->assertInertia(fn (Assert $page) => $page
                    ->where('branding.name', 'Webku Hosting')
                    ->where('branding.color', '#0f766e')
                    ->where('branding.logo_url', fn (string $url): bool => str_contains($url, '/branding/'.$settings->id.'/logo')));
        }

        foreach ([User::factory()->admin()->create(), User::factory()->create(), User::factory()->for(User::factory()->reseller(), 'owner')->create()] as $other) {
            $this->actingAs($other)
                ->get(route('dashboard'))
                ->assertInertia(fn (Assert $page) => $page->where('branding', null));
        }
    }

    public function test_the_logo_is_served_to_anyone_as_the_image_it_is()
    {
        $reseller = User::factory()->reseller()->create();

        $this->actingAs($reseller)->post(route('reseller.branding.update'), ['logo' => UploadedFile::fake()->image('logo.webp', 64, 64)]);
        auth()->logout();

        $settings = ResellerSetting::query()->sole();

        $this->get(route('branding.logo', $settings))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $other = ResellerSetting::factory()->create();
        $this->get(route('branding.logo', $other))->assertNotFound();
    }

    public function test_a_new_logo_replaces_the_old_one_and_it_can_be_removed()
    {
        $reseller = User::factory()->reseller()->create();

        $this->actingAs($reseller)->post(route('reseller.branding.update'), ['logo' => UploadedFile::fake()->image('logo.png')]);
        $this->actingAs($reseller)->post(route('reseller.branding.update'), ['logo' => UploadedFile::fake()->image('logo.jpg')]);

        Storage::disk('local')->assertMissing('branding/'.$reseller->id.'.png');
        Storage::disk('local')->assertExists('branding/'.$reseller->id.'.jpg');

        $this->actingAs($reseller)->post(route('reseller.branding.update'), ['brand_name' => 'Webku', 'remove_logo' => '1'])->assertSessionHasNoErrors();

        Storage::disk('local')->assertMissing('branding/'.$reseller->id.'.jpg');
        $this->assertNull(ResellerSetting::query()->sole()->logo_path);
    }

    public function test_branding_is_checked()
    {
        $reseller = User::factory()->reseller()->create();

        $this->actingAs($reseller)
            ->post(route('reseller.branding.update'), [
                'brand_name' => str_repeat('x', 41),
                'brand_color' => 'red; } body { display: none',
            ])
            ->assertSessionHasErrors(['brand_name', 'brand_color']);

        foreach ([
            UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            UploadedFile::fake()->createWithContent('logo.png', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
            UploadedFile::fake()->image('logo.png', 2500, 100),
            UploadedFile::fake()->image('logo.png')->size(600),
        ] as $logo) {
            $this->actingAs($reseller)->post(route('reseller.branding.update'), ['logo' => $logo])->assertSessionHasErrors('logo');
        }

        $this->assertDatabaseCount('reseller_settings', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_the_zones_of_a_resellers_accounts_name_its_own_nameservers()
    {
        $reseller = User::factory()->reseller()->create();
        $account = User::factory()->for($reseller, 'owner')->create(['username' => 'budi']);
        $other = User::factory()->for(User::factory()->reseller(), 'owner')->create(['username' => 'sari']);
        $this->app->make(DomainService::class)->create($account, 'example.com', $account);
        $this->app->make(DomainService::class)->create($other, 'example.org', $other);
        Task::query()->delete();

        $this->actingAs($reseller)
            ->put(route('reseller.branding.nameservers.update'), ['ns1' => 'NS1.Webku.id', 'ns2' => 'ns2.webku.id.'])
            ->assertSessionHasNoErrors();

        $this->assertSame(['ns1.webku.id', 'ns2.webku.id'], ResellerSetting::query()->sole()->nameservers);
        $sync = Task::query()->where('action', 'dns.sync_zone')->sole();
        $this->assertSame(['example.com', ['ns1.webku.id', 'ns2.webku.id']], [$sync->payload['zone'], $sync->payload['nameservers']]);

        $this->actingAs($account)
            ->get(route('dns.index', ['zone' => $account->dnsZones()->sole()->id]))
            ->assertInertia(fn (Assert $page) => $page->where('nameservers', ['ns1.webku.id', 'ns2.webku.id']));

        // None goes back to the panel's.
        Task::query()->delete();
        $this->actingAs($reseller)
            ->put(route('reseller.branding.nameservers.update'), ['ns1' => '', 'ns2' => ''])
            ->assertSessionHasNoErrors();

        $this->assertNull(ResellerSetting::query()->sole()->nameservers);
        $this->assertSame(['ns1.example.net', 'ns2.example.net'], Task::query()->where('action', 'dns.sync_zone')->sole()->payload['nameservers']);
    }

    public function test_nameservers_are_checked()
    {
        $reseller = User::factory()->reseller()->create();

        $this->actingAs($reseller)->put(route('reseller.branding.nameservers.update'), ['ns1' => '', 'ns2' => 'ns2.webku.id'])->assertSessionHasErrors('ns1');
        $this->actingAs($reseller)->put(route('reseller.branding.nameservers.update'), ['ns1' => 'ns1.webku.id', 'ns2' => 'ns1.webku.id'])->assertSessionHasErrors('ns2');
        $this->actingAs($reseller)->put(route('reseller.branding.nameservers.update'), ['ns1' => 'not a host', 'ns2' => ''])->assertSessionHasErrors('ns1');

        $this->assertDatabaseCount('reseller_settings', 0);
    }

    public function test_resellers_see_their_branding_page()
    {
        $reseller = User::factory()->reseller()->create();
        ResellerSetting::factory()->for($reseller)->create(['nameservers' => ['ns1.webku.id', 'ns2.webku.id']]);

        $this->actingAs($reseller)
            ->get(route('reseller.branding.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('reseller/branding')
                ->where('branding', ['name' => 'Webku Hosting', 'color' => '#0f766e', 'logo_url' => null])
                ->where('nameservers', ['ns1.webku.id', 'ns2.webku.id'])
                ->where('panelNameservers', ['ns1.example.net', 'ns2.example.net'])
                ->where('serverIp', '203.0.113.10'));
    }
}
