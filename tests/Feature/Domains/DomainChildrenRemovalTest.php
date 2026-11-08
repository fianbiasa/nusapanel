<?php

namespace Tests\Feature\Domains;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Enums\DomainStatus;
use App\Jobs\RunAgentTask;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Deleting a domain removes its subdomains and pointers from the server
 * first, and the domain itself once they are gone.
 */
class DomainChildrenRemovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_deleting_a_domain_removes_its_subdomains_and_pointers_first()
    {
        [$account, $domain, $subdomain, $pointer] = $this->domainWithChildren();

        $this->actingAs($account)
            ->delete(route('domains.destroy', $domain))
            ->assertSessionHasNoErrors();

        foreach ([$domain, $subdomain, $pointer] as $website) {
            $this->assertSame(DomainStatus::Deleting, $website->fresh()->status);
        }

        // Only the children are queued; the domain waits for them.
        $this->assertSame(
            ['blog.example.com', 'example.net'],
            Task::query()->where('action', 'web.remove_domain')->orderBy('id')->pluck('payload')->pluck('domain')->all(),
        );

        foreach (['domain.deleted', 'subdomain.deleted', 'pointer.deleted'] as $action) {
            $this->assertDatabaseHas(AuditLog::class, ['action' => $action, 'actor_id' => $account->id]);
        }

        $this->runRemoval('blog.example.com');

        $this->assertNull($subdomain->fresh());
        $this->assertSame(1, Task::query()->where('action', 'web.remove_domain')->where('status', 'queued')->count());

        $this->runRemoval('example.net');

        $this->assertNull($pointer->fresh());
        $this->assertSame(['username' => 'budi', 'domain' => 'example.com'], $this->queuedRemoval()->payload);

        $this->runRemoval('example.com');

        $this->assertNull($domain->fresh());
        $this->assertDatabaseCount('domains', 0);
    }

    public function test_a_child_that_cannot_be_removed_fails_its_domain_too_so_it_can_be_deleted_again()
    {
        [$account, $domain, $subdomain, $pointer] = $this->domainWithChildren();

        $this->actingAs($account)->delete(route('domains.destroy', $domain));

        $this->runRemoval('example.net');
        $this->runRemoval('blog.example.com', new AgentException('nginx -t failed'));

        $this->assertNull($pointer->fresh());
        $this->assertSame(DomainStatus::Failed, $subdomain->fresh()->status);
        $this->assertSame(DomainStatus::Failed, $domain->fresh()->status);
        $this->assertSame(0, Task::query()->where('action', 'web.remove_domain')->where('status', 'queued')->count());

        // Deleting it again retries the subdomain, then the domain.
        $this->actingAs($account)
            ->delete(route('domains.destroy', $domain))
            ->assertSessionHasNoErrors();

        $this->assertSame('blog.example.com', $this->queuedRemoval()->payload['domain']);
    }

    public function test_a_domain_cannot_be_deleted_while_a_child_is_still_being_set_up()
    {
        $account = User::factory()->create();
        $domain = Domain::factory()->for($account, 'account')->create(['name' => 'example.com']);
        $subdomain = Domain::factory()->subdomainOf($domain, 'blog')->pending()->create();

        $this->actingAs($account)
            ->delete(route('domains.destroy', $domain))
            ->assertSessionHasErrors(['domain' => 'Wait until blog.example.com has been set up, then delete the domain again.']);

        $this->assertSame(DomainStatus::Active, $domain->fresh()->status);
        $this->assertSame(DomainStatus::Pending, $subdomain->fresh()->status);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_a_subdomain_can_be_deleted_on_its_own()
    {
        [$account, $domain, $subdomain] = $this->domainWithChildren();

        $this->actingAs($account)
            ->delete(route('domains.destroy', $subdomain))
            ->assertSessionHasNoErrors();

        $this->assertSame(DomainStatus::Active, $domain->fresh()->status);
        $this->assertSame('blog.example.com', $this->queuedRemoval()->payload['domain']);

        $this->runRemoval('blog.example.com');

        $this->assertNull($subdomain->fresh());
        $this->assertSame(DomainStatus::Active, $domain->fresh()->status);
        $this->assertSame(0, Task::query()->where('action', 'web.remove_domain')->where('status', 'queued')->count());
    }

    /**
     * @return array{User, Domain, Domain, Domain}
     */
    private function domainWithChildren(): array
    {
        $account = User::factory()->create(['username' => 'budi']);
        $domain = Domain::factory()->for($account, 'account')->create(['name' => 'example.com']);
        $subdomain = Domain::factory()->subdomainOf($domain, 'blog')->create();
        $pointer = Domain::factory()->pointerOf($domain)->create(['name' => 'example.net']);

        return [$account, $domain, $subdomain, $pointer];
    }

    private function queuedRemoval(): Task
    {
        return Task::query()->where('action', 'web.remove_domain')->where('status', 'queued')->sole();
    }

    /**
     * Run the queued removal of the website named $domain against a mocked
     * agent that returns success, or throws $error.
     */
    private function runRemoval(string $domain, ?AgentException $error = null): void
    {
        $task = Task::query()
            ->where('action', 'web.remove_domain')
            ->where('status', 'queued')
            ->get()
            ->sole(fn (Task $task): bool => $task->payload['domain'] === $domain);

        $agent = Mockery::mock(AgentClient::class);
        $call = $agent->shouldReceive('call')->once();

        $error === null ? $call->andReturn([]) : $call->andThrow($error);

        (new RunAgentTask($task))->handle($agent);
    }
}
