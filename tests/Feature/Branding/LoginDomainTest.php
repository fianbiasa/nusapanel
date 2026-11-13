<?php

namespace Tests\Feature\Branding;

use App\Enums\TaskStatus;
use App\Events\AgentTaskFinished;
use App\Models\AuditLog;
use App\Models\ResellerSetting;
use App\Models\Task;
use App\Models\User;
use App\Services\DomainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LoginDomainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['hosting.public_ip' => '203.0.113.10', 'app.url' => 'https://server.example.net:2222']);
    }

    public function test_a_reseller_has_the_panel_set_up_at_its_own_domain()
    {
        $reseller = User::factory()->reseller()->create();

        $this->actingAs($reseller)
            ->put(route('reseller.branding.login-domain.update'), ['login_domain' => 'Panel.Webku.id'])
            ->assertSessionHasNoErrors();

        $settings = ResellerSetting::query()->sole();
        $this->assertSame(['panel.webku.id', 'pending', null], [$settings->login_domain, $settings->login_domain_status, $settings->login_domain_error]);

        $task = Task::query()->sole();
        $this->assertSame(['panel.add_login_domain', ['domain' => 'panel.webku.id'], $reseller->id], [$task->action, $task->payload, $task->user_id]);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'reseller.login_domain_changed', 'actor_id' => $reseller->id]);

        $this->finish($task, TaskStatus::Success);
        $this->assertSame('active', $settings->fresh()->login_domain_status);

        $this->actingAs($reseller)
            ->get(route('reseller.branding.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('loginDomain', ['domain' => 'panel.webku.id', 'status' => 'active', 'error' => null]));
    }

    public function test_a_failed_set_up_shows_why_and_can_be_tried_again()
    {
        $reseller = User::factory()->reseller()->create();

        $this->actingAs($reseller)->put(route('reseller.branding.login-domain.update'), ['login_domain' => 'panel.webku.id']);
        $this->finish(Task::query()->sole(), TaskStatus::Failed, 'panel.webku.id does not reach this server over HTTP');

        $settings = ResellerSetting::query()->sole();
        $this->assertSame(['failed', 'panel.webku.id does not reach this server over HTTP'], [$settings->login_domain_status, $settings->login_domain_error]);

        $this->actingAs($reseller)->put(route('reseller.branding.login-domain.update'), ['login_domain' => 'panel.webku.id'])->assertSessionHasNoErrors();

        $this->assertSame(['pending', null], [$settings->fresh()->login_domain_status, $settings->fresh()->login_domain_error]);
        $this->assertSame(2, Task::query()->where('action', 'panel.add_login_domain')->count());
    }

    public function test_changing_or_clearing_the_domain_takes_the_old_one_down()
    {
        $reseller = User::factory()->reseller()->create();

        $this->actingAs($reseller)->put(route('reseller.branding.login-domain.update'), ['login_domain' => 'panel.webku.id']);
        $old = Task::query()->sole();
        $this->actingAs($reseller)->put(route('reseller.branding.login-domain.update'), ['login_domain' => 'cp.webku.id']);

        $this->assertSame([
            'panel.add_login_domain panel.webku.id',
            'panel.remove_login_domain panel.webku.id',
            'panel.add_login_domain cp.webku.id',
        ], $this->tasks());

        // The old domain's late result changes nothing.
        $this->finish($old, TaskStatus::Success);
        $this->assertSame(['cp.webku.id', 'pending'], [ResellerSetting::query()->sole()->login_domain, ResellerSetting::query()->sole()->login_domain_status]);

        $this->actingAs($reseller)->put(route('reseller.branding.login-domain.update'), ['login_domain' => ''])->assertSessionHasNoErrors();

        $this->assertSame('panel.remove_login_domain cp.webku.id', $this->tasks()[3]);
        $this->assertNull(ResellerSetting::query()->sole()->login_domain);
    }

    public function test_websites_server_names_and_other_resellers_domains_cannot_be_login_domains()
    {
        $reseller = User::factory()->reseller()->create();
        $account = User::factory()->create();
        $this->app->make(DomainService::class)->create($account, 'shop.example.com', $account);
        ResellerSetting::factory()->create(['login_domain' => 'panel.other.id']);
        Task::query()->delete();

        foreach (['shop.example.com', 'panel.other.id', 'server.example.net', 'cp.server.example.net', 'not a domain'] as $domain) {
            $this->actingAs($reseller)
                ->put(route('reseller.branding.login-domain.update'), ['login_domain' => $domain])
                ->assertSessionHasErrors('login_domain');
        }

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_a_login_domain_cannot_become_a_website()
    {
        ResellerSetting::factory()->create(['login_domain' => 'panel.webku.id', 'login_domain_status' => 'active']);
        $account = User::factory()->create();

        $this->expectException(ValidationException::class);

        $this->app->make(DomainService::class)->create($account, 'panel.webku.id', $account);
    }

    public function test_the_sign_in_page_at_a_login_domain_shows_the_resellers_brand()
    {
        ResellerSetting::factory()->create(['brand_name' => 'Webku Hosting', 'login_domain' => 'panel.webku.id', 'login_domain_status' => 'active']);
        ResellerSetting::factory()->create(['brand_name' => 'Not yet', 'login_domain' => 'panel.pending.id', 'login_domain_status' => 'pending']);

        $this->get('https://panel.webku.id/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('branding.name', 'Webku Hosting'));

        foreach (['https://panel.pending.id/login', 'https://server.example.net:2222/login'] as $url) {
            $this->get($url)->assertInertia(fn (Assert $page) => $page->where('branding', null));
        }
    }

    private function finish(Task $task, TaskStatus $status, ?string $error = null): void
    {
        $task->forceFill(['status' => $status, 'error' => $error])->save();

        event(new AgentTaskFinished($task));
    }

    /**
     * @return list<string>
     */
    private function tasks(): array
    {
        return Task::query()->orderBy('id')->get()->map(fn (Task $task): string => $task->action.' '.$task->payload['domain'])->all();
    }
}
