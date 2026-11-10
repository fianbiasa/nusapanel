<?php

namespace Tests\Feature\Admin;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Agent\FakeAgentClient;
use App\Enums\TaskStatus;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Task;
use App\Models\User;
use App\Services\ServerSettingsService;
use App\Support\PhpSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\TestCase;

class ServerSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_hosting_accounts_cannot_reach_server_settings()
    {
        $account = User::factory()->create();

        $this->actingAs($account)->get(route('admin.server-settings.index'))->assertForbidden();
        $this->actingAs($account)->put(route('admin.server-settings.hostname'), ['hostname' => 'server2.example.com'])->assertForbidden();
        $this->actingAs($account)->post(route('admin.server-settings.port'), ['port' => 8443])->assertForbidden();
        $this->actingAs($account)->post(route('admin.server-settings.port.confirm'))->assertForbidden();
        $this->actingAs($account)->delete(route('admin.server-settings.port.cancel'))->assertForbidden();
        $this->actingAs($account)->put(route('admin.server-settings.timezone'), ['timezone' => 'UTC'])->assertForbidden();
        $this->actingAs($account)->post(route('admin.server-settings.hostname-certificate'))->assertForbidden();
        $this->actingAs($account)->put(route('admin.server-settings.php-uploads'), ['limit' => '64M', 'default' => '8M'])->assertForbidden();

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_the_page_shows_what_the_server_reports_and_the_upload_sizes()
    {
        $this->app->instance(AgentClient::class, new FakeAgentClient);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.server-settings.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/server-settings/index')
                ->where('error', null)
                ->where('server.hostname', 'server1.example.test')
                ->where('server.timezone', 'Asia/Jakarta')
                ->where('server.certificate.lets_encrypt', true)
                ->where('certificate_request', ['pending' => false, 'error' => null])
                ->where('php_uploads.limit', '1024M')
                ->where('php_uploads.default', '64M'));
    }

    public function test_an_agent_that_does_not_answer_still_leaves_the_upload_sizes()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('call')->once()->andThrow(new AgentException('agent is not running')));

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.server-settings.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('server', null)
                ->where('error', 'The agent did not answer. Check that rayxod is running: systemctl status rayxod')
                ->where('php_uploads.limit', '1024M'));
    }

    public function test_changing_the_hostname_hands_it_to_the_agent_and_is_audited()
    {
        $agent = $this->app->instance(AgentClient::class, new FakeAgentClient);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('admin.server-settings.hostname'), ['hostname' => ' Server2.Example.COM. '])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Changing the hostname to server2.example.com. This takes a few minutes; follow it on this page.');

        $this->assertSame([['method' => 'system.change_hostname', 'params' => ['hostname' => 'server2.example.com']]], $agent->calls());
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'server.hostname_change_started', 'actor_id' => $admin->id]);
    }

    public function test_a_hostname_that_is_not_a_full_name_never_reaches_the_agent()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('call'));
        $admin = User::factory()->admin()->create();

        foreach (['server2', 'server 2.example.com', '-bad.example.com', 'example.123'] as $hostname) {
            $this->actingAs($admin)
                ->put(route('admin.server-settings.hostname'), ['hostname' => $hostname])
                ->assertSessionHasErrors(['hostname' => 'The hostname must be a fully qualified name, such as server1.example.com.']);
        }
    }

    public function test_what_the_agent_refuses_about_a_hostname_is_explained()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('call')->twice()
            ->andThrowExceptions([
                new AgentException('the hostname is already being changed', -32000, reason: 'busy'),
                new AgentException('shop.example.com is the domain of a website; choose a hostname no account uses', -32000),
            ]));
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('admin.server-settings.hostname'), ['hostname' => 'server2.example.com'])
            ->assertSessionHasErrors(['hostname' => 'The hostname is already being changed.']);

        $this->actingAs($admin)
            ->put(route('admin.server-settings.hostname'), ['hostname' => 'shop.example.com'])
            ->assertSessionHasErrors(['hostname' => 'The hostname cannot be changed: shop.example.com is the domain of a website; choose a hostname no account uses']);

        $this->assertDatabaseMissing(AuditLog::class, ['action' => 'server.hostname_change_started']);
    }

    public function test_trying_a_panel_port_opens_it_through_the_agent()
    {
        $agent = $this->app->instance(AgentClient::class, new FakeAgentClient);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.server-settings.port'), ['port' => 8443])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Port 8443 is open for 5 minutes. Open the panel through it to confirm.');

        $this->assertSame([['method' => 'panel.port_trial', 'params' => ['port' => 8443]]], $agent->calls());
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'server.panel_port_tried', 'actor_id' => $admin->id]);
    }

    public function test_a_panel_port_outside_the_allowed_range_never_reaches_the_agent()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('call'));
        $admin = User::factory()->admin()->create();

        foreach ([80, 443, 70000, 'abc'] as $port) {
            $this->actingAs($admin)
                ->post(route('admin.server-settings.port'), ['port' => $port])
                ->assertSessionHasErrors('port');
        }
    }

    public function test_what_the_agent_refuses_about_a_port_is_explained()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('call')->once()
            ->andThrow(new AgentException('port 9000 is already in use on this server', -32000)));

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.server-settings.port'), ['port' => 9000])
            ->assertSessionHasErrors(['port' => 'The panel port cannot be changed: port 9000 is already in use on this server']);

        $this->assertDatabaseMissing(AuditLog::class, ['action' => 'server.panel_port_tried']);
    }

    public function test_a_port_is_only_confirmed_through_itself()
    {
        $this->mock(AgentClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('call')->with('system.settings', [], 20)
                ->andReturn(['port_trial' => ['port' => 8443, 'previous' => 2222, 'confirmed' => false]]);
            $mock->shouldReceive('call')->with('panel.port_confirm', [], 30)->once()->andReturn(['confirmed' => true]);
        });
        $admin = User::factory()->admin()->create();
        $path = route('admin.server-settings.port.confirm', absolute: false);

        // Through the panel's own port, nothing proves 8443 can be reached.
        $this->actingAs($admin)
            ->post('https://panel.example.test:2222'.$path)
            ->assertSessionHasErrors(['port' => 'Open the panel through port 8443 to confirm it: that shows the port can be reached.']);

        $this->assertDatabaseMissing(AuditLog::class, ['action' => 'server.panel_port_changed']);

        $this->actingAs($admin)
            ->post('https://panel.example.test:8443'.$path)
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Moving the panel to port 8443. This takes a minute; follow it on this page.');

        $this->assertDatabaseHas(AuditLog::class, ['action' => 'server.panel_port_changed', 'actor_id' => $admin->id]);
    }

    public function test_confirming_without_a_port_being_tried_is_refused()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('call')->once()->with('system.settings', [], 20)->andReturn(['port_trial' => null]));

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.server-settings.port.confirm'))
            ->assertSessionHasErrors(['port' => 'No port is being tried.']);
    }

    public function test_a_port_being_tried_can_be_closed_again()
    {
        $agent = $this->app->instance(AgentClient::class, new FakeAgentClient);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->delete(route('admin.server-settings.port.cancel'))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'The port tried is closed again.');

        $this->assertSame('panel.port_cancel', $agent->calls()[0]['method']);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'server.panel_port_cancelled', 'actor_id' => $admin->id]);
    }

    public function test_changing_the_timezone_asks_the_agent_and_is_audited()
    {
        $agent = $this->app->instance(AgentClient::class, new FakeAgentClient);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('admin.server-settings.timezone'), ['timezone' => 'Asia/Makassar'])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'The server now runs on Asia/Makassar time.');

        $this->assertSame([['method' => 'system.set_timezone', 'params' => ['timezone' => 'Asia/Makassar']]], $agent->calls());
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'server.timezone_changed', 'actor_id' => $admin->id]);
    }

    public function test_a_timezone_that_does_not_exist_never_reaches_the_agent()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('call'));

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('admin.server-settings.timezone'), ['timezone' => 'Mars/Olympus'])
            ->assertSessionHasErrors('timezone');
    }

    public function test_a_timezone_the_server_refuses_is_explained()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('call')->once()
            ->andThrow(new AgentException('invalid params: "Asia/Jakarta" is not a timezone of this server', -32602)));

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('admin.server-settings.timezone'), ['timezone' => 'Asia/Jakarta'])
            ->assertSessionHasErrors(['timezone' => 'The server could not change its timezone: invalid params: "Asia/Jakarta" is not a timezone of this server']);

        $this->assertDatabaseMissing(AuditLog::class, ['action' => 'server.timezone_changed']);
    }

    public function test_the_hostname_certificate_is_requested_once_at_a_time()
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.server-settings.hostname-certificate'))
            ->assertSessionHasNoErrors();

        $task = Task::query()->sole();
        $this->assertSame('ssl.issue_hostname', $task->action);
        $this->assertNull($task->user_id);
        $this->assertSame($admin->id, $task->actor_id);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'server.hostname_ssl_requested', 'actor_id' => $admin->id]);

        $this->actingAs($admin)
            ->post(route('admin.server-settings.hostname-certificate'))
            ->assertSessionHasErrors(['certificate' => 'The certificate is already being requested.']);

        $this->assertDatabaseCount('tasks', 1);

        $task->forceFill(['status' => TaskStatus::Failed, 'error' => 'server1.example.test does not reach this server over HTTP'])->save();

        $this->assertSame(
            ['pending' => false, 'error' => 'server1.example.test does not reach this server over HTTP'],
            app(ServerSettingsService::class)->hostnameCertificateRequest(),
        );
    }

    public function test_the_default_upload_size_may_not_exceed_the_limit()
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('admin.server-settings.php-uploads'), ['limit' => '128M', 'default' => '256M'])
            ->assertSessionHasErrors(['default' => 'The default size may not be larger than the limit.']);

        $this->actingAs($admin)
            ->put(route('admin.server-settings.php-uploads'), ['limit' => '3G', 'default' => '64M'])
            ->assertSessionHasErrors('limit');

        $this->assertDatabaseCount('settings', 0);
    }

    public function test_websites_follow_the_upload_sizes_of_the_server()
    {
        $admin = User::factory()->admin()->create();
        $account = User::factory()->create(['username' => 'budi']);
        $plain = Domain::factory()->for($account, 'account')->create(['name' => 'plain.example']);
        $large = Domain::factory()->for($account, 'account')->create([
            'name' => 'large.example',
            'php_settings' => ['post_max_size' => '512M', 'upload_max_filesize' => '512M'],
        ]);

        $this->actingAs($admin)
            ->put(route('admin.server-settings.php-uploads'), ['limit' => '256M', 'default' => '128M'])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Upload sizes saved; 2 websites are being updated.');

        $this->assertDatabaseHas(AuditLog::class, ['action' => 'server.php_uploads_changed', 'actor_id' => $admin->id]);

        // A website that chose nothing gets the default; one above the limit
        // is held to it, and keeps its own choice for when the limit rises.
        $payloads = Task::query()->where('action', 'web.apply_domain')->get()->keyBy('payload.domain');
        $this->assertSame(['post_max_size' => '128M', 'upload_max_filesize' => '128M'], $payloads['plain.example']->payload['php_settings']);
        $this->assertSame(['post_max_size' => '256M', 'upload_max_filesize' => '256M'], $payloads['large.example']->payload['php_settings']);
        $this->assertSame('512M', $large->fresh()->php_settings['upload_max_filesize']);
        $this->assertNull($plain->fresh()->php_settings);

        // Customers are offered sizes up to the limit, starting from the default.
        $this->actingAs($account)
            ->get(route('domains.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('php_setting_options.sizes', ['2M', '8M', '16M', '32M', '64M', '128M', '256M'])
                ->where('php_setting_options.defaults', PhpSettings::defaults('128M'))
                ->where('domains.0.php_settings.upload_max_filesize', '256M'));

        $fields = [
            'memory_limit' => '256M', 'max_execution_time' => 120, 'max_input_time' => 120,
            'max_input_vars' => 3000, 'display_errors' => '0',
        ];

        $this->actingAs($account)
            ->put(route('domains.php-settings.update', $plain), [...$fields, 'upload_max_filesize' => '512M', 'post_max_size' => '512M'])
            ->assertSessionHasErrors(['upload_max_filesize', 'post_max_size']);

        // Choosing the server's default stores nothing, so the website keeps
        // following the default.
        $this->actingAs($account)
            ->put(route('domains.php-settings.update', $plain), [...$fields, 'upload_max_filesize' => '128M', 'post_max_size' => '128M'])
            ->assertSessionHasNoErrors();

        $this->assertNull($plain->fresh()->php_settings);

        // Saving the same sizes again applies nothing.
        Task::query()->delete();

        $this->actingAs($admin)
            ->put(route('admin.server-settings.php-uploads'), ['limit' => '256M', 'default' => '128M'])
            ->assertInertiaFlash('toast.message', 'Nothing changed.');

        $this->assertDatabaseCount('tasks', 0);
    }
}
