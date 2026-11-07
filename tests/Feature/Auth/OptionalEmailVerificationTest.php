<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OptionalEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unverified_accounts_can_use_the_panel_while_mail_is_only_logged()
    {
        config(['mail.default' => 'log']);

        $this->actingAs(User::factory()->unverified()->create())
            ->get(route('dashboard'))
            ->assertOk();
    }

    public function test_unverified_accounts_must_verify_once_mail_is_delivered()
    {
        config(['mail.default' => 'smtp']);

        $this->actingAs(User::factory()->unverified()->create())
            ->get(route('dashboard'))
            ->assertRedirect(route('verification.notice'));
    }

    public function test_changing_email_does_not_lock_the_account_while_mail_is_only_logged()
    {
        config(['mail.default' => 'log']);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('profile.update'), ['name' => $user->name, 'email' => 'baru@example.com'])
            ->assertSessionHasNoErrors();

        $this->assertNull($user->fresh()->email_verified_at);

        $this->get(route('dashboard'))->assertOk();
    }

    public function test_profile_page_only_offers_verification_when_mail_is_delivered()
    {
        $user = User::factory()->unverified()->create();

        config(['mail.default' => 'log']);
        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('mustVerifyEmail', false));

        config(['mail.default' => 'smtp']);
        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('mustVerifyEmail', true));
    }
}
