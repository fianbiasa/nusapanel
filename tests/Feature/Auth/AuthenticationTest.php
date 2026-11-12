<?php

namespace Tests\Feature\Auth;

use App\Enums\AccountStatus;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Features;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered()
    {
        $response = $this->get(route('login'));

        $response->assertOk();
    }

    public function test_users_can_authenticate_with_their_username()
    {
        $user = User::factory()->create(['username' => 'budi']);

        $response = $this->post(route('login.store'), [
            'username' => 'budi',
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_users_can_authenticate_with_their_email_address()
    {
        $user = User::factory()->create(['email' => 'budi@example.com']);

        $this->post(route('login.store'), [
            'username' => 'Budi@Example.com',
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
    }

    public function test_successful_login_is_recorded()
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), [
            'username' => $user->username,
            'password' => 'password',
        ]);

        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.login',
            'actor_id' => $user->id,
        ]);
    }

    public function test_failed_login_is_recorded_without_the_password()
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), [
            'username' => $user->username,
            'password' => 'wrong-password',
        ]);

        $log = AuditLog::query()->where('action', 'auth.failed')->sole();

        $this->assertNull($log->actor_id);
        $this->assertSame(['username' => $user->username], $log->changes);
    }

    public function test_users_with_two_factor_enabled_are_redirected_to_two_factor_challenge()
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]);

        $user = User::factory()->withTwoFactor()->create();

        $response = $this->post(route('login'), [
            'username' => $user->username,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('two-factor.login'));
        $response->assertSessionHas('login.id', $user->id);
        $this->assertGuest();
    }

    public function test_an_unknown_username_still_has_its_password_checked()
    {
        $this->assertFalse(Cache::has('auth.unknown-user-hash'));

        $this->post(route('login.store'), [
            'username' => 'nobody',
            'password' => 'some-password',
        ]);

        $this->assertGuest();

        // The password was checked against a hash, as for a real account, so
        // the answer takes as long and its time does not tell which usernames
        // exist.
        $hash = Cache::get('auth.unknown-user-hash');
        $this->assertTrue(is_string($hash) && Hash::info($hash)['algoName'] !== 'unknown');
    }

    public function test_users_can_not_authenticate_with_invalid_password()
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), [
            'username' => $user->username,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_suspended_accounts_can_not_authenticate()
    {
        $user = User::factory()->suspended()->create();

        $response = $this->post(route('login.store'), [
            'username' => $user->username,
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_accounts_suspended_during_a_session_are_logged_out()
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $user->forceFill(['status' => AccountStatus::Suspended])->save();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_users_can_logout()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('logout'));

        $response->assertRedirect(route('home'));

        $this->assertGuest();
    }

    public function test_users_are_rate_limited()
    {
        $user = User::factory()->create();

        RateLimiter::increment(md5('login'.implode('|', [$user->username, '127.0.0.1'])), amount: 5);

        $response = $this->post(route('login.store'), [
            'username' => $user->username,
            'password' => 'wrong-password',
        ]);

        $response->assertTooManyRequests();
    }
}
