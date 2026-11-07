<?php

namespace Tests\Feature\Accounts;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Enums\AccountStatus;
use App\Enums\DomainStatus;
use App\Enums\TaskStatus;
use App\Jobs\RunAgentTask;
use App\Models\Domain;
use App\Models\Task;
use App\Models\User;
use App\Services\AccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * The queue runs synchronously in tests, so each service call below sends
 * its task to the (mocked) agent and applies the result straight away.
 */
class ApplyAccountTaskResultTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_successful_create_task_activates_the_account_and_stores_its_uid()
    {
        $this->agentReturns('account.create', ['username' => 'budi', 'uid' => 1001, 'gid' => 1001, 'home' => '/home/budi']);

        $account = $this->createAccount();

        $this->assertSame(AccountStatus::Active, $account->status);
        $this->assertSame(1001, $account->uid);
    }

    public function test_a_failed_create_task_marks_the_account_as_failed()
    {
        $this->agentFails('account.create', 'useradd: user budi already exists');

        $account = $this->createAccount();

        $this->assertSame(AccountStatus::Failed, $account->status);
        $this->assertNull($account->uid);
        $this->assertSame('useradd: user budi already exists', Task::query()->sole()->error);
    }

    public function test_a_create_task_that_fails_unexpectedly_marks_the_account_as_failed()
    {
        $admin = User::factory()->admin()->create();
        $account = User::factory()->create(['status' => AccountStatus::Pending]);
        $task = Task::create([
            'user_id' => $account->id,
            'actor_id' => $admin->id,
            'action' => 'account.create',
            'payload' => ['username' => $account->username],
            'status' => TaskStatus::Running,
        ]);

        (new RunAgentTask($task))->failed(new RuntimeException('The job timed out.'));

        $this->assertSame(AccountStatus::Failed, $account->fresh()->status);
    }

    public function test_a_successful_create_task_queues_the_set_up_of_the_accounts_pending_domains()
    {
        Queue::fake();

        $admin = User::factory()->admin()->create();
        $account = $this->app->make(AccountService::class)->create([
            'username' => 'budi',
            'name' => 'Budi Santoso',
            'email' => 'budi@example.com',
            'password' => 'correct-horse-battery',
            'domain' => 'example.com',
        ], $admin);

        $this->runQueuedTask('account.create', ['username' => 'budi', 'uid' => 1001]);

        $task = Task::query()->where('action', 'web.apply_domain')->sole();

        $this->assertSame([
            'username' => 'budi',
            'domain' => 'example.com',
            'php_version' => '8.4',
            'ssl' => false,
            'force_https' => false,
        ], $task->payload);
        $this->assertSame($account->id, $task->user_id);
        $this->assertSame($admin->id, $task->actor_id);
        $this->assertSame(DomainStatus::Pending, Domain::query()->sole()->status);

        Queue::assertPushed(RunAgentTask::class, fn (RunAgentTask $job) => $job->task->is($task));
    }

    public function test_a_failed_create_task_fails_the_accounts_pending_domains()
    {
        Queue::fake();

        $this->app->make(AccountService::class)->create([
            'username' => 'budi',
            'name' => 'Budi Santoso',
            'email' => 'budi@example.com',
            'password' => 'correct-horse-battery',
            'domain' => 'example.com',
        ], User::factory()->admin()->create());

        $this->runQueuedTask('account.create', new AgentException('useradd: user budi already exists'));

        $this->assertSame(DomainStatus::Failed, Domain::query()->sole()->status);
        $this->assertFalse(Task::query()->where('action', 'web.apply_domain')->exists());
    }

    public function test_a_successful_delete_task_removes_the_account()
    {
        $this->agentReturns('account.delete', []);

        $account = User::factory()->create(['username' => 'budi']);

        $this->app->make(AccountService::class)->delete($account, User::factory()->admin()->create());

        $this->assertModelMissing($account);
        $this->assertSame(TaskStatus::Success, Task::query()->sole()->status);
    }

    public function test_a_failed_delete_task_marks_the_account_as_failed()
    {
        $this->agentFails('account.delete', 'userdel: user budi is currently used by process 42');

        $account = User::factory()->create(['username' => 'budi']);

        $this->app->make(AccountService::class)->delete($account, User::factory()->admin()->create());

        $this->assertSame(AccountStatus::Failed, $account->fresh()->status);
    }

    public function test_a_failed_suspend_task_leaves_the_account_suspended()
    {
        $this->agentFails('account.suspend', 'usermod: user budi does not exist');

        $account = User::factory()->create(['username' => 'budi']);

        $this->app->make(AccountService::class)->suspend($account, 'Abuse', User::factory()->admin()->create());

        $account->refresh();

        $this->assertSame(AccountStatus::Suspended, $account->status);
        $this->assertSame('Abuse', $account->suspend_reason);
        $this->assertSame(TaskStatus::Failed, Task::query()->sole()->status);
    }

    private function createAccount(): User
    {
        $this->app->make(AccountService::class)->create([
            'username' => 'budi',
            'name' => 'Budi Santoso',
            'email' => 'budi@example.com',
            'password' => 'correct-horse-battery',
        ], User::factory()->admin()->create());

        return User::query()->where('username', 'budi')->sole();
    }

    /**
     * Run a task the faked queue holds, as the queue worker would.
     *
     * @param  array<string, mixed>|AgentException  $result
     */
    private function runQueuedTask(string $action, array|AgentException $result): void
    {
        $task = Task::query()->where('action', $action)->sole();

        $agent = Mockery::mock(AgentClient::class);
        $call = $agent->shouldReceive('call')->once()->with($action, ['username' => 'budi'], config('agent.task_timeout'));

        $result instanceof AgentException ? $call->andThrow($result) : $call->andReturn($result);

        (new RunAgentTask($task))->handle($agent);
    }

    /**
     * @param  array<string, mixed>  $output
     */
    private function agentReturns(string $method, array $output): void
    {
        $this->mock(AgentClient::class, function (MockInterface $mock) use ($method, $output) {
            $mock->shouldReceive('call')->once()->with($method, ['username' => 'budi'], config('agent.task_timeout'))->andReturn($output);
        });
    }

    private function agentFails(string $method, string $error): void
    {
        $this->mock(AgentClient::class, function (MockInterface $mock) use ($method, $error) {
            $mock->shouldReceive('call')->once()->with($method, ['username' => 'budi'], config('agent.task_timeout'))->andThrow(new AgentException($error));
        });
    }
}
