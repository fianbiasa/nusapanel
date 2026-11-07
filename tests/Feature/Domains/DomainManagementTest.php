<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Jobs\RunAgentTask;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DomainManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['app.url' => 'https://panel.example.net']);
    }

    public function test_guests_are_redirected_to_login()
    {
        $this->get(route('domains.index'))->assertRedirect(route('login'));
    }

    public function test_admins_and_resellers_cannot_reach_domains()
    {
        foreach ([User::factory()->admin()->create(), User::factory()->reseller()->create()] as $actor) {
            $this->actingAs($actor)->get(route('domains.index'))->assertForbidden();
            $this->actingAs($actor)->post(route('domains.store'), ['domain' => 'example.com'])->assertForbidden();
        }

        $this->assertDatabaseCount('domains', 0);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_accounts_see_only_their_own_domains_primary_first()
    {
        $account = User::factory()->create();
        Domain::factory()->for($account, 'account')->create(['name' => 'xn--bcher-kva.de']);
        Domain::factory()->for($account, 'account')->primary()->pending()->create(['name' => 'zeta.com']);
        Domain::factory()->create(['name' => 'other.com']);

        $this->actingAs($account)
            ->get(route('domains.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('domains/index')
                ->has('domains', 2)
                ->where('domains.0.name', 'zeta.com')
                ->where('domains.0.is_primary', true)
                ->where('domains.0.status', 'pending')
                ->has('domains.1', fn (Assert $row) => $row
                    ->where('name', 'xn--bcher-kva.de')
                    ->where('display_name', 'bücher.de')
                    ->where('php_version', '8.4')
                    ->where('is_primary', false)
                    ->where('status', 'active')
                    ->etc()));
    }

    public function test_accounts_can_add_a_domain_that_the_agent_then_sets_up()
    {
        $account = User::factory()->create(['username' => 'budi']);

        $this->actingAs($account)
            ->post(route('domains.store'), ['domain' => '  Example.COM. '])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('domains.index'));

        $domain = Domain::query()->sole();

        $this->assertSame('example.com', $domain->name);
        $this->assertSame($account->id, $domain->user_id);
        $this->assertSame('8.4', $domain->php_version);
        $this->assertFalse($domain->is_primary);
        $this->assertSame(DomainStatus::Pending, $domain->status);

        $task = Task::query()->sole();

        $this->assertSame('web.apply_domain', $task->action);
        $this->assertSame([
            'username' => 'budi',
            'domain' => 'example.com',
            'php_version' => '8.4',
            'ssl' => false,
            'force_https' => false,
        ], $task->payload);
        $this->assertSame($account->id, $task->user_id);
        $this->assertSame($account->id, $task->actor_id);

        Queue::assertPushed(RunAgentTask::class, fn (RunAgentTask $job) => $job->task->is($task));

        $this->assertDatabaseHas(AuditLog::class, [
            'action' => 'domain.created',
            'actor_id' => $account->id,
            'subject_id' => $domain->id,
        ]);
    }

    public function test_internationalized_domains_are_stored_in_punycode()
    {
        $account = User::factory()->create();

        $this->actingAs($account)
            ->post(route('domains.store'), ['domain' => 'Bücher.de'])
            ->assertSessionHasNoErrors();

        $this->assertSame('xn--bcher-kva.de', Domain::query()->sole()->name);
        $this->assertSame('xn--bcher-kva.de', Task::query()->sole()->payload['domain']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedDomains(): array
    {
        return [
            'single label' => ['localhost'],
            'underscore' => ['my_site.com'],
            'leading hyphen' => ['-example.com'],
            'trailing hyphen' => ['example-.com'],
            'empty label' => ['example..com'],
            'numeric top-level label' => ['example.123'],
            'ip address' => ['192.168.1.10'],
            'label over 63 characters' => [str_repeat('a', 64).'.com'],
            'name over 253 characters' => [implode('.', array_fill(0, 4, str_repeat('a', 63))).'.com'],
            'url' => ['http://example.com'],
            'path' => ['example.com/blog'],
            'wildcard' => ['*.example.com'],
        ];
    }

    #[DataProvider('malformedDomains')]
    public function test_malformed_domains_are_rejected(string $name)
    {
        $this->actingAs(User::factory()->create())
            ->post(route('domains.store'), ['domain' => $name])
            ->assertSessionHasErrors(['domain' => 'The domain must be a valid domain name, such as example.com.']);

        $this->assertDatabaseCount('domains', 0);
        Queue::assertNothingPushed();
    }

    public function test_domains_starting_with_www_are_rejected()
    {
        $this->actingAs(User::factory()->create())
            ->post(route('domains.store'), ['domain' => 'www.example.com'])
            ->assertSessionHasErrors(['domain' => 'Add the domain without "www." — www is served automatically.']);

        $this->assertDatabaseCount('domains', 0);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function serverHostnames(): array
    {
        return [
            'the hostname itself' => ['panel.example.net'],
            'a subdomain of it' => ['shop.panel.example.net'],
        ];
    }

    #[DataProvider('serverHostnames')]
    public function test_the_server_hostname_and_its_subdomains_are_rejected(string $name)
    {
        $this->actingAs(User::factory()->create())
            ->post(route('domains.store'), ['domain' => $name])
            ->assertSessionHasErrors(['domain' => 'The domain belongs to this server\'s own hostname and cannot be added.']);

        $this->assertDatabaseCount('domains', 0);
    }

    public function test_a_domain_cannot_be_added_twice_by_the_same_account()
    {
        $account = User::factory()->create();
        Domain::factory()->for($account, 'account')->create(['name' => 'example.com']);

        $this->actingAs($account)
            ->post(route('domains.store'), ['domain' => 'example.com'])
            ->assertSessionHasErrors(['domain' => 'You have already added this domain.']);

        $this->assertDatabaseCount('domains', 1);
        Queue::assertNothingPushed();
    }

    public function test_a_domain_of_another_account_cannot_be_added()
    {
        Domain::factory()->create(['name' => 'example.com']);

        $this->actingAs(User::factory()->create())
            ->post(route('domains.store'), ['domain' => 'example.com'])
            ->assertSessionHasErrors(['domain' => 'This domain is already in use by another account.']);

        $this->assertDatabaseCount('domains', 1);
        Queue::assertNothingPushed();
    }

    public function test_a_subdomain_of_another_accounts_domain_cannot_be_added()
    {
        Domain::factory()->create(['name' => 'example.com']);

        $this->actingAs(User::factory()->create())
            ->post(route('domains.store'), ['domain' => 'blog.shop.example.com'])
            ->assertSessionHasErrors(['domain' => 'This domain is a subdomain of a domain that belongs to another account.']);

        $this->assertDatabaseCount('domains', 1);
    }

    public function test_a_parent_of_another_accounts_domain_cannot_be_added()
    {
        Domain::factory()->create(['name' => 'blog.example.com']);

        $this->actingAs(User::factory()->create())
            ->post(route('domains.store'), ['domain' => 'example.com'])
            ->assertSessionHasErrors(['domain' => 'Another account already uses a subdomain of this domain.']);

        $this->assertDatabaseCount('domains', 1);
    }

    public function test_accounts_can_add_subdomains_of_their_own_domains()
    {
        $account = User::factory()->create();
        Domain::factory()->for($account, 'account')->create(['name' => 'example.com']);

        $this->actingAs($account)
            ->post(route('domains.store'), ['domain' => 'blog.example.com'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas(Domain::class, ['name' => 'blog.example.com', 'user_id' => $account->id]);
    }

    public function test_accounts_can_delete_a_domain_and_keep_its_files()
    {
        $account = User::factory()->create(['username' => 'budi']);
        $domain = Domain::factory()->for($account, 'account')->create(['name' => 'example.com']);

        $this->actingAs($account)
            ->from(route('domains.index'))
            ->delete(route('domains.destroy', $domain))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('domains.index'));

        $this->assertSame(DomainStatus::Deleting, $domain->fresh()->status);

        $task = Task::query()->sole();

        $this->assertSame('web.remove_domain', $task->action);
        $this->assertSame(['username' => 'budi', 'domain' => 'example.com'], $task->payload);
        $this->assertSame($account->id, $task->user_id);
        $this->assertSame($account->id, $task->actor_id);

        Queue::assertPushed(RunAgentTask::class, fn (RunAgentTask $job) => $job->task->is($task));

        $this->assertDatabaseHas(AuditLog::class, [
            'action' => 'domain.deleted',
            'actor_id' => $account->id,
            'subject_id' => $domain->id,
        ]);
    }

    public function test_failed_domains_can_be_deleted()
    {
        $account = User::factory()->create();
        $domain = Domain::factory()->for($account, 'account')->failed()->create();

        $this->actingAs($account)
            ->delete(route('domains.destroy', $domain))
            ->assertSessionHasNoErrors();

        $this->assertSame(DomainStatus::Deleting, $domain->fresh()->status);
    }

    /**
     * @return array<string, array{DomainStatus}>
     */
    public static function busyStatuses(): array
    {
        return [
            'pending' => [DomainStatus::Pending],
            'deleting' => [DomainStatus::Deleting],
        ];
    }

    #[DataProvider('busyStatuses')]
    public function test_domains_that_are_waiting_on_the_agent_cannot_be_deleted(DomainStatus $status)
    {
        $account = User::factory()->create();
        $domain = Domain::factory()->for($account, 'account')->create(['status' => $status]);

        $this->actingAs($account)
            ->delete(route('domains.destroy', $domain))
            ->assertSessionHasErrors(['domain' => 'This domain is still being set up or is already being deleted.']);

        $this->assertSame($status, $domain->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_accounts_cannot_delete_another_accounts_domain()
    {
        $domain = Domain::factory()->create();

        $this->actingAs(User::factory()->create())
            ->delete(route('domains.destroy', $domain))
            ->assertNotFound();

        $this->assertSame(DomainStatus::Active, $domain->fresh()->status);
        $this->assertDatabaseCount('tasks', 0);
    }
}
