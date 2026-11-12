<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\PasswordResetLinkController;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::resetPasswords());
    }

    public function test_reset_password_link_screen_can_be_rendered()
    {
        $response = $this->get(route('password.request'));

        $response->assertOk();
    }

    public function test_reset_password_link_can_be_requested()
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_password_screen_can_be_rendered()
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
            $response = $this->get(route('password.reset', $notification->token));

            $response->assertOk();

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token()
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $response = $this->post(route('password.update'), [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login'));

            return true;
        });
    }

    public function test_a_reset_signs_the_account_out_everywhere()
    {
        Notification::fake();
        $user = User::factory()->create(['remember_token' => 'stolen-cookie-token']);
        DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $this->post(route('password.update'), [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'a-new-password-1',
                'password_confirmation' => 'a-new-password-1',
            ])->assertSessionHasNoErrors();

            return true;
        });

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertNotSame('stolen-cookie-token', $user->refresh()->remember_token);
    }

    public function test_the_answer_does_not_tell_which_emails_have_accounts()
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'budi@example.com']);

        $known = $this->from(route('password.request'))->post(route('password.email'), ['email' => 'budi@example.com']);
        $unknown = $this->from(route('password.request'))->post(route('password.email'), ['email' => 'nobody@example.com']);

        foreach ([$known, $unknown] as $response) {
            $response->assertSessionHasNoErrors()
                ->assertSessionHas('status', 'If an account uses that email, a link to reset its password has been sent to it.');
        }

        Notification::assertSentToTimes($user, ResetPassword::class, 1);
    }

    public function test_reset_links_are_limited_per_email()
    {
        Notification::fake();
        $this->travelTo(now());
        $user = User::factory()->create(['email' => 'budi@example.com']);

        // The broker itself sends at most one link a minute; past that, the
        // panel's own limit stops a flood over the following minutes.
        foreach (range(1, 5) as $minute) {
            $this->post(route('password.email'), ['email' => 'budi@example.com'])->assertSessionHasNoErrors();
            $this->travel(61)->seconds();
        }

        Notification::assertSentToTimes($user, ResetPassword::class, PasswordResetLinkController::PER_EMAIL);
    }

    public function test_password_cannot_be_reset_with_invalid_token(): void
    {
        $user = User::factory()->create();

        $response = $this->post(route('password.update'), [
            'token' => 'invalid-token',
            'email' => $user->email,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertSessionHasErrors('email');
    }
}
