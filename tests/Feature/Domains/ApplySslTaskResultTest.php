<?php

namespace Tests\Feature\Domains;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Enums\AccountStatus;
use App\Enums\DomainStatus;
use App\Enums\SslStatus;
use App\Enums\TaskStatus;
use App\Jobs\RunAgentTask;
use App\Models\Domain;
use App\Models\Task;
use App\Models\User;
use App\Services\DomainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * The queue is faked so that follow-up tasks are recorded rather than run;
 * each test runs the task under test against a mocked agent itself.
 */
class ApplySslTaskResultTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_an_issued_certificate_is_recorded_and_the_website_switched_to_https()
    {
        $account = User::factory()->create(['username' => 'budi']);
        $domain = Domain::factory()->for($account, 'account')->create(['name' => 'example.com']);
        $admin = User::factory()->admin()->create();

        $this->app->make(DomainService::class)->requestSsl($domain, $admin);
        $this->runTask($this->soleTask('ssl.issue'), [
            'names' => ['example.com', 'www.example.com'],
            'expires_at' => '2027-02-05T10:30:00+07:00',
        ]);

        $domain->refresh();

        $this->assertSame(SslStatus::Active, $domain->ssl_status);
        $this->assertSame(['example.com', 'www.example.com'], $domain->ssl_names);
        $this->assertSame('2027-02-05T03:30:00+00:00', $domain->ssl_expires_at->toIso8601String());
        $this->assertNull($domain->ssl_error);

        $apply = $this->soleTask('web.apply_domain');

        $this->assertSame([
            'username' => 'budi',
            'domain' => 'example.com',
            'php_version' => '8.4',
            'ssl' => true,
            'force_https' => false,
        ], $apply->payload);
        $this->assertSame($account->id, $apply->user_id);
        $this->assertSame($admin->id, $apply->actor_id);

        Queue::assertPushed(RunAgentTask::class, fn (RunAgentTask $job) => $job->task->is($apply));
    }

    public function test_a_failed_certificate_request_keeps_the_agents_error()
    {
        $account = User::factory()->create();
        $domain = Domain::factory()->for($account, 'account')->create(['name' => 'example.com']);

        $this->app->make(DomainService::class)->requestSsl($domain, $account);
        $this->runTask($this->soleTask('ssl.issue'), new AgentException('example.com does not point to this server yet.'));

        $domain->refresh();

        $this->assertSame(SslStatus::Failed, $domain->ssl_status);
        $this->assertSame('example.com does not point to this server yet.', $domain->ssl_error);
        $this->assertSame(DomainStatus::Active, $domain->status);
        $this->assertFalse(Task::query()->where('action', 'web.apply_domain')->exists());
    }

    public function test_a_certificate_issued_while_the_account_is_being_deleted_is_not_applied()
    {
        $account = User::factory()->create();
        $domain = Domain::factory()->for($account, 'account')->create();

        $this->app->make(DomainService::class)->requestSsl($domain, $account);
        $account->forceFill(['status' => AccountStatus::Deleting])->save();
        $this->runTask($this->soleTask('ssl.issue'), ['names' => [$domain->name], 'expires_at' => '2027-02-05T10:30:00Z']);

        $this->assertSame(SslStatus::Active, $domain->fresh()->ssl_status);
        $this->assertFalse(Task::query()->where('action', 'web.apply_domain')->exists());
    }

    public function test_a_removed_certificate_is_deleted_once_the_website_no_longer_uses_it()
    {
        $account = User::factory()->create(['username' => 'budi']);
        $domain = Domain::factory()->for($account, 'account')->sslActive()->create(['name' => 'example.com']);
        $admin = User::factory()->admin()->create();

        $this->app->make(DomainService::class)->removeSsl($domain, $admin);
        $this->runTask($this->soleTask('web.apply_domain'), []);

        $this->assertSame(SslStatus::Removing, $domain->fresh()->ssl_status);

        $delete = $this->soleTask('ssl.delete');

        $this->assertSame(['username' => 'budi', 'domain' => 'example.com'], $delete->payload);
        $this->assertSame($account->id, $delete->user_id);
        $this->assertSame($admin->id, $delete->actor_id);

        Queue::assertPushed(RunAgentTask::class, fn (RunAgentTask $job) => $job->task->is($delete));

        $this->runTask($delete, []);

        $domain->refresh();

        $this->assertNull($domain->ssl_status);
        $this->assertNull($domain->ssl_names);
        $this->assertNull($domain->ssl_expires_at);
        $this->assertNull($domain->ssl_error);
        $this->assertFalse($domain->force_https);
        $this->assertSame(DomainStatus::Active, $domain->status);
    }

    public function test_a_certificate_the_agent_could_not_delete_is_still_no_longer_used()
    {
        $account = User::factory()->create();
        $domain = Domain::factory()->for($account, 'account')->sslActive()->create();

        $this->app->make(DomainService::class)->removeSsl($domain, $account);
        $this->runTask($this->soleTask('web.apply_domain'), []);

        $delete = $this->soleTask('ssl.delete');
        $this->runTask($delete, new AgentException('certbot delete failed'));

        $this->assertNull($domain->fresh()->ssl_status);
        $this->assertSame(TaskStatus::Failed, $delete->fresh()->status);
        $this->assertSame('certbot delete failed', $delete->fresh()->error);
    }

    public function test_a_certificate_stays_active_when_the_website_could_not_stop_using_it()
    {
        $account = User::factory()->create();
        $domain = Domain::factory()->for($account, 'account')->sslActive()->create();

        $this->app->make(DomainService::class)->removeSsl($domain, $account);
        $this->runTask($this->soleTask('web.apply_domain'), new AgentException('nginx -t failed'));

        $this->assertSame(SslStatus::Active, $domain->fresh()->ssl_status);
        $this->assertFalse(Task::query()->where('action', 'ssl.delete')->exists());
    }

    public function test_an_earlier_apply_with_the_certificate_does_not_delete_it()
    {
        $account = User::factory()->create();
        $domain = Domain::factory()->for($account, 'account')->sslActive()->create();
        $domains = $this->app->make(DomainService::class);

        $domains->setForceHttps($domain, true, $account);
        $domains->removeSsl($domain, $account);

        $applies = Task::query()->where('action', 'web.apply_domain')->get();
        $withCertificate = $applies->sole(fn (Task $task) => $task->payload['ssl'] === true);
        $withoutCertificate = $applies->sole(fn (Task $task) => $task->payload['ssl'] === false);

        $this->runTask($withCertificate, []);

        $this->assertFalse(Task::query()->where('action', 'ssl.delete')->exists());

        $this->runTask($withoutCertificate, []);

        $this->assertTrue(Task::query()->where('action', 'ssl.delete')->exists());
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
        $call = $agent->shouldReceive('call')->once()->with($task->action, $task->payload, config('agent.task_timeout'));

        $result instanceof AgentException ? $call->andThrow($result) : $call->andReturn($result);

        (new RunAgentTask($task))->handle($agent);
    }

    private function soleTask(string $action): Task
    {
        return Task::query()->where('action', $action)->sole();
    }
}
