<?php

namespace Tests\Feature\Settings;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_page_is_displayed()
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]);
        Features::passkeys([
            'confirmPassword' => true,
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/security')
                ->where('canManagePasskeys', true)
                ->where('passkeys', [])
                ->where('canManageTwoFactor', true)
                ->where('twoFactorEnabled', false),
            );
    }

    public function test_security_page_requires_password_confirmation_when_enabled()
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        $user = User::factory()->create();

        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]);

        $response = $this->actingAs($user)
            ->get(route('security.edit'));

        $response->assertRedirect(route('password.confirm'));
    }

    public function test_security_page_renders_without_two_factor_when_feature_is_disabled()
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        config(['fortify.features' => []]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/security')
                ->where('canManagePasskeys', false)
                ->where('passkeys', [])
                ->where('canManageTwoFactor', false)
                ->missing('twoFactorEnabled')
                ->missing('requiresConfirmation'),
            );
    }

    public function test_password_can_be_updated()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from(route('security.edit'))
            ->put(route('user-password.update'), [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('security.edit'));

        $this->assertTrue(Hash::check('new-password', $user->refresh()->password));
    }

    public function test_correct_password_must_be_provided_to_update_password()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from(route('security.edit'))
            ->put(route('user-password.update'), [
                'current_password' => 'wrong-password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response
            ->assertSessionHasErrors('current_password')
            ->assertRedirect(route('security.edit'));
    }

    public function test_the_page_lists_the_accounts_own_logins_newest_first()
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $chrome = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36';

        (new AuditLog)->forceFill(['action' => 'auth.login', 'subject_type' => $user->getMorphClass(), 'subject_id' => $user->id, 'actor_id' => $user->id, 'ip_address' => '203.0.113.7', 'user_agent' => $chrome, 'created_at' => now()->subHour()])->save();
        (new AuditLog)->forceFill(['action' => 'auth.failed', 'subject_type' => $user->getMorphClass(), 'subject_id' => $user->id, 'ip_address' => '198.51.100.9', 'user_agent' => 'curl/8.5.0', 'created_at' => now()])->save();
        (new AuditLog)->forceFill(['action' => 'auth.login', 'subject_type' => $other->getMorphClass(), 'subject_id' => $other->id, 'actor_id' => $other->id, 'ip_address' => '192.0.2.1'])->save();
        (new AuditLog)->forceFill(['action' => 'domain.created', 'subject_type' => $user->getMorphClass(), 'subject_id' => $user->id])->save();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('loginHistory', 2)
                ->where('loginHistory.0.succeeded', false)
                ->where('loginHistory.0.ip_address', '198.51.100.9')
                ->where('loginHistory.0.browser', 'curl')
                ->where('loginHistory.1.succeeded', true)
                ->where('loginHistory.1.browser', 'Chrome')
                ->where('loginHistory.1.os', 'macOS')
                ->missing('loginHistory.0.user_agent'));
    }
}
