<?php

namespace Tests\Feature\Admin;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\TestCase;

class ProcessMonitorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    /**
     * @var array<string, mixed>
     */
    private array $process = ['pid' => 4321, 'start' => '98765', 'signal' => 'TERM', 'user' => 'budi', 'command' => 'php-fpm: pool budi'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    public function test_the_administrator_sees_the_busiest_processes()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('call')->once()
            ->with('process.list', [], 30)
            ->andReturn([
                'processes' => [['pid' => 4321, 'user' => 'budi', 'cpu' => 87.5, 'memory' => 52428800, 'command' => 'php-fpm: pool budi', 'start' => '98765']],
                'users' => [['user' => 'budi', 'uid' => 1001, 'processes' => 1, 'cpu' => 87.5, 'memory' => 52428800]],
                'cpus' => 2,
                'memory_total' => 2147483648,
                'total' => 1,
                'truncated' => false,
            ]));

        $this->actingAs($this->admin)
            ->get(route('admin.processes.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/processes/index')
                ->where('snapshot.error', null)
                ->where('snapshot.result.processes.0.pid', 4321)
                ->where('snapshot.result.users.0.cpu', 87.5)
                ->where('signals', ['TERM', 'KILL']));
    }

    public function test_an_agent_that_does_not_answer_is_shown()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('call')->once()
            ->andThrow(new AgentException('connection refused')));

        $this->actingAs($this->admin)
            ->get(route('admin.processes.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('snapshot.result', null)
                ->whereType('snapshot.error', 'string'));
    }

    public function test_a_process_is_stopped_and_recorded()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('call')->once()
            ->with('process.kill', ['pid' => 4321, 'start' => '98765', 'signal' => 'TERM'], 30)
            ->andReturn([]));

        $this->actingAs($this->admin)
            ->post(route('admin.processes.kill'), $this->process)
            ->assertSessionHasNoErrors();

        $log = AuditLog::query()->where('action', 'server.process_killed')->sole();
        $this->assertSame(4321, $log->changes['pid']);
        $this->assertSame('php-fpm: pool budi', $log->changes['command']);
    }

    public function test_a_process_that_ended_meanwhile_is_explained()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('call')->once()
            ->andThrow(new AgentException('the process has ended', -32000, reason: 'gone')));

        $this->actingAs($this->admin)
            ->post(route('admin.processes.kill'), $this->process)
            ->assertSessionHasErrors(['process' => 'Process 4321 has ended already.']);

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_only_a_listed_process_with_a_known_signal_can_be_stopped()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('call'));

        foreach ([['pid' => 1], ['signal' => 'HUP'], ['start' => '1; rm'], ['start' => '']] as $change) {
            $this->actingAs($this->admin)
                ->post(route('admin.processes.kill'), [...$this->process, ...$change])
                ->assertSessionHasErrors();
        }
    }

    public function test_other_roles_have_no_process_monitor()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('call'));

        foreach ([User::factory()->create(), User::factory()->reseller()->create()] as $user) {
            $this->actingAs($user)->get(route('admin.processes.index'))->assertForbidden();
            $this->actingAs($user)->post(route('admin.processes.kill'), $this->process)->assertForbidden();
        }
    }

    public function test_an_account_sees_what_its_own_processes_use()
    {
        $account = User::factory()->create(['username' => 'budi']);

        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('call')
            ->with('process.list', ['username' => 'budi'], 30)
            ->andReturn([
                'processes' => [],
                'users' => [['user' => 'budi', 'uid' => 1001, 'processes' => 3, 'cpu' => 12.5, 'memory' => 73400320]],
                'cpus' => 2,
                'memory_total' => 2147483648,
            ]));

        $this->actingAs($account)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->missing('resourceUsage')
                ->loadDeferredProps(fn (Assert $reload) => $reload
                    ->where('resourceUsage', ['processes' => 3, 'cpu' => 12.5, 'memory' => 73400320, 'cpus' => 2, 'memory_total' => 2147483648])));
    }
}
