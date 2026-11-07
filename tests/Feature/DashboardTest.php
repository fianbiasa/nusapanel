<?php

namespace Tests\Feature;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_users_and_resellers_do_not_receive_server_information()
    {
        foreach ([User::factory()->create(), User::factory()->reseller()->create()] as $account) {
            $this->actingAs($account)
                ->get(route('dashboard'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('dashboard')
                    ->missing('systemInfo'));
        }
    }

    public function test_admins_receive_server_information_as_a_deferred_prop()
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->missing('systemInfo')
                ->loadDeferredProps(fn (Assert $reload) => $reload
                    ->where('systemInfo.data.hostname', 'server.nusapanel.test')
                    ->where('systemInfo.error', null)));
    }

    public function test_admins_see_an_error_when_the_agent_is_unreachable()
    {
        $this->mock(AgentClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('call')
                ->with('system.info')
                ->andThrow(new AgentException('Connection refused'));
        });

        $this->actingAs(User::factory()->admin()->create());

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps(fn (Assert $reload) => $reload
                    ->where('systemInfo.data', null)
                    ->where('systemInfo.error', 'The server agent could not be reached.')));
    }
}
