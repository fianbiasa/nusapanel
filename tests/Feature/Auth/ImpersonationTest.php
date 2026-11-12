<?php

namespace Tests\Feature\Auth;

use App\Enums\AccountStatus;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\ImpersonationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_an_admin_signs_in_as_a_hosting_account_and_back()
    {
        $admin = User::factory()->admin()->create();
        $account = User::factory()->create(['last_login_at' => null]);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.accounts.login-as', $account))
            ->assertRedirect(route('dashboard'))
            ->assertSessionMissing('auth.password_confirmed_at')
            ->assertSessionHas(ImpersonationService::SESSION_KEY, $admin->id);

        $this->assertAuthenticatedAs($account);
        $this->assertNull($account->fresh()->last_login_at);
        $this->assertDatabaseMissing(AuditLog::class, ['action' => 'auth.login', 'actor_id' => $account->id]);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'account.login_as', 'actor_id' => $admin->id, 'subject_id' => $account->id]);

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.id', $account->id)
                ->where('auth.impersonator.username', $admin->username));

        // What the account does now is recorded with who really did it.
        $this->post(route('cron-jobs.store'), ['schedule' => '*/5 * * * *', 'command' => 'true'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas(AuditLog::class, ['actor_id' => $account->id, 'impersonator_id' => $admin->id]);

        $this->delete(route('impersonation.destroy'))
            ->assertRedirect(route('admin.accounts.index'))
            ->assertSessionMissing(ImpersonationService::SESSION_KEY);

        $this->assertAuthenticatedAs($admin);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'account.login_as_ended', 'actor_id' => $admin->id, 'subject_id' => $account->id]);
    }

    public function test_the_password_of_the_account_is_still_asked_for()
    {
        $admin = User::factory()->admin()->create();
        $account = User::factory()->create();

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.accounts.login-as', $account));

        $this->get(route('profile.edit'))->assertRedirect(route('password.confirm'));
        $this->get(route('security.edit'))->assertRedirect(route('password.confirm'));
    }

    public function test_a_reseller_signs_in_as_its_own_account_and_back()
    {
        $reseller = User::factory()->reseller()->create();
        $account = User::factory()->for($reseller, 'owner')->create();

        $this->actingAs($reseller)
            ->post(route('reseller.accounts.login-as', $account))
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($account);

        $this->delete(route('impersonation.destroy'))
            ->assertRedirect(route('reseller.accounts.index'));
        $this->assertAuthenticatedAs($reseller);
    }

    public function test_no_one_signs_in_as_an_account_that_is_not_active()
    {
        $admin = User::factory()->admin()->create();
        $account = User::factory()->suspended()->create();

        $this->actingAs($admin)
            ->post(route('admin.accounts.login-as', $account))
            ->assertForbidden();

        $this->assertAuthenticatedAs($admin);
    }

    public function test_an_admin_does_not_sign_in_as_another_admin()
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.accounts.login-as', $other))
            ->assertForbidden();

        $this->assertAuthenticatedAs($admin);
    }

    public function test_signing_in_as_one_more_account_is_refused_until_returning()
    {
        $admin = User::factory()->admin()->create();
        $reseller = User::factory()->reseller()->create();
        $account = User::factory()->for($reseller, 'owner')->create();

        $this->actingAs($admin)->post(route('admin.accounts.login-as', $reseller));

        $this->post(route('reseller.accounts.login-as', $account))->assertForbidden();

        $this->assertAuthenticatedAs($reseller);
    }

    public function test_returning_after_the_impersonator_was_suspended_signs_out()
    {
        $admin = User::factory()->admin()->create();
        $account = User::factory()->create();

        $this->actingAs($admin)->post(route('admin.accounts.login-as', $account));

        $admin->forceFill(['status' => AccountStatus::Suspended])->save();

        $this->delete(route('impersonation.destroy'))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_there_is_nothing_to_return_to_without_signing_in_as_someone()
    {
        $this->actingAs(User::factory()->admin()->create())
            ->delete(route('impersonation.destroy'))
            ->assertNotFound();
    }
}
