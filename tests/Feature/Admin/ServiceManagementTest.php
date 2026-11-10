<?php

namespace Tests\Feature\Admin;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Agent\FakeAgentClient;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\TestCase;

class ServiceManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_hosting_accounts_cannot_reach_services()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.services.index'))
            ->assertForbidden();
    }

    public function test_the_page_shows_what_the_agent_reports()
    {
        $this->app->instance(AgentClient::class, new FakeAgentClient);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.services.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/services/index')
                ->where('error', null)
                ->where('report.services.0.unit', 'nginx.service')
                ->where('report.services.0.can_stop', false)
                ->where('report.pools.total', 3)
                ->where('report.events.0.outcome', 'restarted'));
    }

    public function test_an_agent_that_does_not_answer_is_explained()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('call')->once()->andThrow(new AgentException('agent is not running')));

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.services.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('report', null)
                ->where('error', 'The agent did not answer. Check that rayxod is running: systemctl status rayxod'));
    }

    public function test_restarting_a_service_asks_the_agent_and_is_audited()
    {
        $agent = $this->app->instance(AgentClient::class, new FakeAgentClient);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.services.act', ['unit' => 'pure-ftpd-mysql.service', 'action' => 'restart']))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'pure-ftpd-mysql.service is being restarted.');

        $this->assertSame(
            [['method' => 'service.action', 'params' => ['unit' => 'pure-ftpd-mysql.service', 'action' => 'restart']]],
            $agent->calls(),
        );
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'service.restart', 'actor_id' => $admin->id]);
    }

    public function test_what_the_agent_refuses_is_explained()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('call')->once()
            ->andThrow(new AgentException('Nginx refuses its configuration: unknown directive', -32000, reason: 'config_invalid')));

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.services.act', ['unit' => 'nginx.service', 'action' => 'reload']))
            ->assertSessionHasErrors(['service' => 'The configuration has an error, so nginx.service was left as it is: Nginx refuses its configuration: unknown directive']);

        $this->assertDatabaseMissing(AuditLog::class, ['action' => 'service.reload']);
    }

    public function test_unknown_actions_are_not_routed()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('call'));
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/admin/services/nginx.service/kill')
            ->assertNotFound();

        $this->actingAs($admin)
            ->post('/admin/services/..%2Fetc%2Fpasswd/restart')
            ->assertNotFound();
    }
}
