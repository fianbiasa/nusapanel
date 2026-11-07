<?php

namespace Tests\Feature\Domains;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Enums\DomainStatus;
use App\Enums\TaskStatus;
use App\Jobs\RunAgentTask;
use App\Models\Domain;
use App\Models\Task;
use App\Models\User;
use App\Services\DomainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * The queue is faked so that follow-up tasks are recorded rather than run;
 * each test runs the task under test against a mocked agent itself.
 */
class ApplyDomainTaskResultTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_a_successful_apply_task_activates_the_domain()
    {
        $account = User::factory()->create(['username' => 'budi']);
        $domain = $this->app->make(DomainService::class)->create($account, 'example.com', $account);

        $this->runTask($this->soleTask('web.apply_domain'), [
            'domain' => 'example.com',
            'document_root' => '/home/budi/domains/example.com/public_html',
        ]);

        $this->assertSame(DomainStatus::Active, $domain->fresh()->status);
    }

    public function test_a_failed_apply_task_marks_the_domain_as_failed()
    {
        $account = User::factory()->create();
        $domain = $this->app->make(DomainService::class)->create($account, 'example.com', $account);

        $this->runTask($this->soleTask('web.apply_domain'), new AgentException('nginx -t failed'));

        $this->assertSame(DomainStatus::Failed, $domain->fresh()->status);
    }

    public function test_an_apply_task_that_fails_unexpectedly_marks_the_domain_as_failed()
    {
        $account = User::factory()->create();
        $domain = $this->app->make(DomainService::class)->create($account, 'example.com', $account);

        (new RunAgentTask($this->soleTask('web.apply_domain')))->failed(new RuntimeException('The job timed out.'));

        $this->assertSame(DomainStatus::Failed, $domain->fresh()->status);
    }

    public function test_a_late_apply_result_does_not_change_a_domain_that_is_being_deleted()
    {
        $account = User::factory()->create();
        $domain = Domain::factory()->for($account, 'account')->deleting()->create(['name' => 'example.com']);
        $task = $this->makeTask($account, 'web.apply_domain', ['username' => $account->username, 'domain' => 'example.com', 'php_version' => '8.4']);

        $this->runTask($task, []);

        $this->assertSame(DomainStatus::Deleting, $domain->fresh()->status);
    }

    public function test_a_successful_remove_task_deletes_the_domain_and_its_unused_php_pool()
    {
        $account = User::factory()->create(['username' => 'budi']);
        $domain = Domain::factory()->for($account, 'account')->create(['name' => 'example.com', 'php_version' => '8.4']);
        Domain::factory()->for($account, 'account')->create(['php_version' => '8.3']);
        Domain::factory()->create(['php_version' => '8.4']);
        $admin = User::factory()->admin()->create();

        $this->app->make(DomainService::class)->delete($domain, $admin);
        $this->runTask($this->soleTask('web.remove_domain'), []);

        $this->assertModelMissing($domain);

        $pool = $this->soleTask('php.remove_pool');

        $this->assertSame(['username' => 'budi', 'php_version' => '8.4'], $pool->payload);
        $this->assertSame($account->id, $pool->user_id);
        $this->assertSame($admin->id, $pool->actor_id);

        Queue::assertPushed(RunAgentTask::class, fn (RunAgentTask $job) => $job->task->is($pool));
    }

    public function test_a_successful_remove_task_keeps_the_php_pool_while_another_domain_uses_it()
    {
        $account = User::factory()->create();
        $domain = Domain::factory()->for($account, 'account')->create(['php_version' => '8.4']);
        Domain::factory()->for($account, 'account')->pending()->create(['php_version' => '8.4']);

        $this->app->make(DomainService::class)->delete($domain, $account);
        $this->runTask($this->soleTask('web.remove_domain'), []);

        $this->assertModelMissing($domain);
        $this->assertFalse(Task::query()->where('action', 'php.remove_pool')->exists());
    }

    public function test_a_failed_remove_task_marks_the_domain_as_failed()
    {
        $account = User::factory()->create();
        $domain = Domain::factory()->for($account, 'account')->create();

        $this->app->make(DomainService::class)->delete($domain, $account);
        $this->runTask($this->soleTask('web.remove_domain'), new AgentException('nginx reload failed'));

        $this->assertSame(DomainStatus::Failed, $domain->fresh()->status);
        $this->assertFalse(Task::query()->where('action', 'php.remove_pool')->exists());
    }

    /**
     * Run the task's job as the queue worker would, with the agent answering
     * the task's exact payload.
     *
     * @param  array<string, mixed>|AgentException  $result
     */
    private function runTask(Task $task, array|AgentException $result): void
    {
        $agent = Mockery::mock(AgentClient::class);
        $call = $agent->shouldReceive('call')->once()->with($task->action, $task->payload);

        $result instanceof AgentException ? $call->andThrow($result) : $call->andReturn($result);

        (new RunAgentTask($task))->handle($agent);
    }

    private function soleTask(string $action): Task
    {
        return Task::query()->where('action', $action)->sole();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function makeTask(User $account, string $action, array $payload): Task
    {
        return Task::create([
            'user_id' => $account->id,
            'actor_id' => $account->id,
            'action' => $action,
            'payload' => $payload,
            'status' => TaskStatus::Queued,
        ]);
    }
}
