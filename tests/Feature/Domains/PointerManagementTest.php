<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Enums\DomainType;
use App\Enums\PointerMode;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Package;
use App\Models\Task;
use App\Models\User;
use App\Services\DomainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PointerManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['app.url' => 'https://panel.example.net']);
    }

    public function test_accounts_can_add_a_pointer_that_shows_their_domains_website()
    {
        $account = User::factory()->create(['username' => 'budi']);
        $parent = Domain::factory()->for($account, 'account')->create(['name' => 'example.com', 'php_version' => '8.3']);

        $this->actingAs($account)
            ->post(route('pointers.store'), ['domain_id' => $parent->id, 'name' => 'Example.NET', 'mode' => 'alias'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('domains.index'));

        $pointer = Domain::query()->where('type', DomainType::Pointer)->sole();

        $this->assertSame('example.net', $pointer->name);
        $this->assertSame(PointerMode::Alias, $pointer->pointer_mode);
        $this->assertTrue($pointer->parent->is($parent));
        $this->assertSame(DomainStatus::Pending, $pointer->status);

        // An alias runs in the PHP-FPM pool of the website it shows.
        $this->assertSame([
            'username' => 'budi',
            'domain' => 'example.net',
            'php_version' => '8.3',
            'ssl' => false,
            'force_https' => false,
            'kind' => 'alias',
            'parent' => 'example.com',
        ], Task::query()->where('action', 'web.apply_domain')->sole()->payload);

        $this->assertDatabaseHas(AuditLog::class, [
            'action' => 'pointer.created',
            'actor_id' => $account->id,
            'subject_id' => $pointer->id,
        ]);
    }

    public function test_a_redirecting_pointer_is_a_forward_to_the_agent()
    {
        $account = User::factory()->create(['username' => 'budi']);
        $parent = Domain::factory()->for($account, 'account')->create(['name' => 'example.com']);

        $this->actingAs($account)
            ->post(route('pointers.store'), ['domain_id' => $parent->id, 'name' => 'example.net', 'mode' => 'redirect'])
            ->assertSessionHasNoErrors();

        $payload = Task::query()->where('action', 'web.apply_domain')->sole()->payload;

        $this->assertSame('forward', $payload['kind']);
        $this->assertSame('example.com', $payload['parent']);
    }

    public function test_pointers_are_checked_like_domains()
    {
        $account = User::factory()->create();
        $parent = Domain::factory()->for($account, 'account')->create(['name' => 'example.com']);
        Domain::factory()->create(['name' => 'taken.com']);

        $cases = [
            ['name' => 'taken.com', 'error' => ['name' => 'This domain is already in use by another account.']],
            ['name' => 'shop.taken.com', 'error' => ['name' => 'This domain is a subdomain of a domain that belongs to another account.']],
            ['name' => 'www.example.org', 'error' => 'name'],
            ['name' => 'panel.example.net', 'error' => 'name'],
            ['name' => 'example.org;', 'error' => 'name'],
            ['name' => 'example.org', 'mode' => 'proxy', 'error' => 'mode'],
        ];

        foreach ($cases as $case) {
            $this->actingAs($account)
                ->post(route('pointers.store'), ['domain_id' => $parent->id, 'mode' => 'alias', ...$case])
                ->assertSessionHasErrors($case['error']);
        }

        $this->assertDatabaseCount('domains', 2);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_a_pointer_cannot_point_to_another_accounts_domain()
    {
        $account = User::factory()->create();
        $foreign = Domain::factory()->create(['name' => 'other.com']);

        $this->actingAs($account)
            ->post(route('pointers.store'), ['domain_id' => $foreign->id, 'name' => 'example.net', 'mode' => 'alias'])
            ->assertSessionHasErrors('domain_id');

        $this->assertDatabaseCount('domains', 1);
    }

    public function test_pointers_count_against_the_pointer_limit()
    {
        $account = User::factory()->create([
            'package_id' => Package::factory()->create(['domains' => 1, 'pointers' => 0])->id,
        ]);
        $parent = Domain::factory()->for($account, 'account')->create(['name' => 'example.com']);

        $this->actingAs($account)
            ->post(route('pointers.store'), ['domain_id' => $parent->id, 'name' => 'example.net', 'mode' => 'alias'])
            ->assertSessionHasErrors([
                'name' => 'Your package allows 0 pointers. Remove a pointer or ask your provider for a bigger package.',
            ]);

        $this->assertDatabaseCount('domains', 1);
    }

    public function test_rebuilding_the_websites_sends_pointers_with_their_kind_and_parent()
    {
        $account = User::factory()->create(['username' => 'budi']);
        $parent = Domain::factory()->for($account, 'account')->create(['name' => 'example.com']);
        Domain::factory()->pointerOf($parent, PointerMode::Redirect)->create(['name' => 'example.net']);

        $this->assertSame(2, $this->app->make(DomainService::class)->reapplyAll());

        $payloads = Task::query()->orderBy('id')->pluck('payload')->all();

        $this->assertSame('example.com', $payloads[0]['domain']);
        $this->assertArrayNotHasKey('kind', $payloads[0]);
        $this->assertSame(['example.net', 'forward', 'example.com'], [$payloads[1]['domain'], $payloads[1]['kind'], $payloads[1]['parent']]);
    }
}
