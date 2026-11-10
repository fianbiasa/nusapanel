<?php

namespace Tests\Feature\Tasks;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Agent\FakeAgentClient;
use App\Enums\TaskStatus;
use App\Jobs\RunAgentTask;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class RunAgentTaskTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatching_records_a_queued_task_and_queues_the_job()
    {
        Queue::fake();

        $admin = User::factory()->admin()->create();
        $account = User::factory()->create();

        $task = $this->app->make(TaskDispatcher::class)
            ->dispatch('web.apply_domain', ['domain' => 'example.com'], $account, $admin);

        $this->assertSame(TaskStatus::Queued, $task->status);
        $this->assertSame($account->id, $task->user_id);
        $this->assertSame($admin->id, $task->actor_id);

        Queue::assertPushed(RunAgentTask::class, fn (RunAgentTask $job) => $job->task->is($task));
    }

    public function test_task_payload_is_encrypted_at_rest()
    {
        Queue::fake();

        $task = $this->app->make(TaskDispatcher::class)
            ->dispatch('db.user_password', ['password' => 'super-secret']);

        $storedPayload = DB::table('tasks')->where('id', $task->id)->value('payload');

        $this->assertStringNotContainsString('super-secret', $storedPayload);
        $this->assertSame(['password' => 'super-secret'], $task->fresh()->payload);
    }

    public function test_a_successful_agent_call_marks_the_task_as_succeeded()
    {
        $task = $this->app->make(TaskDispatcher::class)->dispatch('system.info')->fresh();

        $this->assertSame(TaskStatus::Success, $task->status);
        $this->assertSame(100, $task->progress);
        $this->assertSame(1, $task->attempts);
        $this->assertSame('server.rayxo.test', $task->output['hostname']);
        $this->assertNotNull($task->finished_at);

        $agent = $this->app->make(AgentClient::class);
        $this->assertInstanceOf(FakeAgentClient::class, $agent);
        $this->assertSame([['method' => 'system.info', 'params' => []]], $agent->calls());
    }

    public function test_agent_errors_mark_the_task_as_failed_without_retrying()
    {
        $this->mock(AgentClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('call')->once()->andThrow(new AgentException('Domain already exists.'));
        });

        $task = $this->app->make(TaskDispatcher::class)
            ->dispatch('web.apply_domain', ['domain' => 'example.com'])
            ->fresh();

        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertSame('Domain already exists.', $task->error);
        $this->assertSame(1, $task->attempts);
        $this->assertNotNull($task->finished_at);
    }

    public function test_finished_tasks_are_not_sent_to_the_agent_again()
    {
        $this->mock(AgentClient::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('call');
        });

        $task = Task::create(['action' => 'system.info', 'status' => TaskStatus::Success]);

        (new RunAgentTask($task))->handle($this->app->make(AgentClient::class));

        $this->assertSame(TaskStatus::Success, $task->fresh()->status);
    }
}
