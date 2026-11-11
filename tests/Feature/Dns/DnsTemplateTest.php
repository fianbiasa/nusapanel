<?php

namespace Tests\Feature\Dns;

use App\Models\AuditLog;
use App\Models\DnsRecord;
use App\Models\DnsTemplateRecord;
use App\Models\DnsZone;
use App\Models\User;
use App\Services\DnsService;
use App\Services\DomainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DnsTemplateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['hosting.public_ip' => '203.0.113.10', 'app.url' => 'https://server.example.net:2222']);
        $this->admin = User::factory()->admin()->create();
    }

    public function test_new_zones_start_with_the_template_as_the_admin_changed_it()
    {
        $this->actingAs($this->admin)
            ->post(route('admin.dns.template.store'), ['type' => 'cname', 'name' => 'Shop', 'content' => 'shops.{domain}.', 'ttl' => 600])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'The template is saved; new zones start with it.');
        $this->actingAs($this->admin)
            ->post(route('admin.dns.template.store'), ['type' => 'TXT', 'name' => '@', 'content' => 'verify={domain} at {ip}', 'ttl' => 3600])
            ->assertSessionHasNoErrors();

        $ftp = DnsTemplateRecord::query()->where('name', 'ftp')->sole();
        $this->actingAs($this->admin)
            ->delete(route('admin.dns.template.destroy', $ftp))
            ->assertSessionHasNoErrors();

        $cname = DnsTemplateRecord::query()->where('type', 'CNAME')->sole();
        $this->assertSame(['shop', 'shops.{domain}', 600], [$cname->name, $cname->content, $cname->ttl]);

        $account = User::factory()->create();
        $this->app->make(DomainService::class)->create($account, 'example.org', $account);

        $records = DnsZone::query()->where('name', 'example.org')->sole()->records()->get()
            ->map(fn (DnsRecord $record): string => "{$record->name} {$record->type} {$record->content}")->all();

        $this->assertContains('shop.example.org CNAME shops.example.org', $records);
        $this->assertContains('example.org TXT verify=example.org at 203.0.113.10', $records);
        $this->assertNotContains('ftp.example.org A 203.0.113.10', $records);
        $this->assertSame(3, AuditLog::query()->where('action', 'dns.template_changed')->count());
    }

    public function test_records_that_need_the_address_are_left_out_without_one()
    {
        config(['hosting.public_ip' => null]);
        $account = User::factory()->create();

        $this->app->make(DomainService::class)->create($account, 'example.org', $account);

        $this->assertSame(['MX'], DnsZone::query()->sole()->records()->pluck('type')->all());
    }

    public function test_the_admin_can_go_back_to_the_default_template()
    {
        DnsTemplateRecord::query()->delete();
        DnsTemplateRecord::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.dns.template.reset'))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'The template is back to the default.');

        $this->assertSame(
            collect(DnsService::DEFAULT_TEMPLATE)->map(fn (array $record): string => "{$record['name']} {$record['type']} {$record['content']}")->all(),
            DnsTemplateRecord::query()->orderBy('id')->get()->map(fn (DnsTemplateRecord $record): string => "{$record->name} {$record->type} {$record->content}")->all(),
        );
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'dns.template_reset', 'actor_id' => $this->admin->id]);
    }

    public function test_the_page_lists_the_template()
    {
        $this->actingAs($this->admin)
            ->get(route('admin.dns.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/dns/index')
                ->has('template', 6)
                ->where('template.0.name', '@')
                ->where('template.0.content', '{ip}'));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidTemplateRecords(): array
    {
        return [
            'unknown placeholder' => [['type' => 'TXT', 'content' => 'id={user}'], 'content'],
            'unclosed placeholder' => [['type' => 'TXT', 'content' => 'id={domain'], 'content'],
            'placeholder in the name' => [['type' => 'A', 'name' => '{domain}', 'content' => '{ip}'], 'name'],
            'not an address once filled in' => [['type' => 'A', 'content' => 'mail.{domain}'], 'content'],
            'cname at the domain' => [['type' => 'CNAME', 'name' => '@', 'content' => 'other.example.net'], 'name'],
            'already in the template' => [['type' => 'A', 'name' => 'www', 'content' => '{ip}'], 'content'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('invalidTemplateRecords')]
    public function test_invalid_template_records_are_refused(array $data, string $error)
    {
        $this->actingAs($this->admin)
            ->post(route('admin.dns.template.store'), [...['name' => 'x', 'ttl' => 3600], ...$data])
            ->assertSessionHasErrors($error);

        $this->assertSame(6, DnsTemplateRecord::query()->count());
    }

    public function test_only_admins_change_the_template()
    {
        $record = DnsTemplateRecord::query()->first();

        foreach ([User::factory()->create(), User::factory()->reseller()->create()] as $user) {
            $this->actingAs($user)->post(route('admin.dns.template.store'), ['type' => 'TXT', 'name' => '@', 'content' => 'x', 'ttl' => 3600])->assertForbidden();
            $this->actingAs($user)->delete(route('admin.dns.template.destroy', $record))->assertForbidden();
            $this->actingAs($user)->post(route('admin.dns.template.reset'))->assertForbidden();
        }

        $this->assertSame(6, DnsTemplateRecord::query()->count());
    }
}
