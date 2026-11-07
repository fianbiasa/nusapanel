<?php

namespace Tests\Feature\Admin;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminAreaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string}>
     */
    public static function adminRoutes(): array
    {
        return [
            'tasks' => ['admin.tasks.index'],
            'audit log' => ['admin.audit-logs.index'],
        ];
    }

    #[DataProvider('adminRoutes')]
    public function test_guests_are_redirected_to_login(string $route)
    {
        $this->get(route($route))->assertRedirect(route('login'));
    }

    #[DataProvider('adminRoutes')]
    public function test_users_and_resellers_are_forbidden(string $route)
    {
        foreach ([User::factory()->create(), User::factory()->reseller()->create()] as $account) {
            $this->actingAs($account)->get(route($route))->assertForbidden();
        }
    }

    public function test_admins_can_list_tasks_without_seeing_payloads()
    {
        $admin = User::factory()->admin()->create();
        $account = User::factory()->create(['username' => 'budi']);

        Task::create([
            'user_id' => $account->id,
            'actor_id' => $admin->id,
            'action' => 'db.user_password',
            'payload' => ['password' => 'super-secret'],
            'status' => TaskStatus::Queued,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.tasks.index'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/tasks/index')
                ->has('tasks.data', 1)
                ->where('tasks.data.0.action', 'db.user_password')
                ->where('tasks.data.0.account', 'budi')
                ->where('tasks.data.0.actor', $admin->username)
                ->missing('tasks.data.0.payload'));

        $response->assertDontSee('super-secret');
    }

    public function test_admins_can_list_audit_logs()
    {
        $admin = User::factory()->admin()->create();

        $this->app->make(AuditLogger::class)->record('account.created', $admin, ['role' => 'admin'], $admin);

        $this->actingAs($admin)
            ->get(route('admin.audit-logs.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/audit-logs/index')
                ->has('logs.data', 1)
                ->where('logs.data.0.action', 'account.created')
                ->where('logs.data.0.actor', $admin->username)
                ->where('logs.data.0.subject', 'User #'.$admin->id));
    }
}
