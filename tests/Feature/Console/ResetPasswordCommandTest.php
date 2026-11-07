<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ResetPasswordCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sets_the_given_password_and_signs_out_existing_sessions()
    {
        $user = User::factory()->admin()->create(['username' => 'admin']);

        DB::table('sessions')->insert([
            'id' => 'old-session',
            'user_id' => $user->id,
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        $this->artisan('nusapanel:reset-password', ['username' => 'admin', '--password' => 'new-Strong-pass-789'])
            ->expectsOutputToContain('Password for [admin] reset')
            ->doesntExpectOutputToContain('New password (shown only once)')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('new-Strong-pass-789', $user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'account.password_reset',
            'subject_id' => (string) $user->id,
        ]);
    }

    public function test_it_generates_a_password_when_none_is_given()
    {
        $user = User::factory()->admin()->create(['username' => 'admin']);
        $oldHash = $user->password;

        $this->artisan('nusapanel:reset-password', ['username' => 'admin'])
            ->expectsOutputToContain('New password (shown only once)')
            ->assertSuccessful();

        $this->assertNotSame($oldHash, $user->fresh()->password);
    }

    public function test_it_fails_for_an_unknown_account()
    {
        $this->artisan('nusapanel:reset-password', ['username' => 'ghost'])
            ->expectsOutputToContain('No account named [ghost]')
            ->assertFailed();
    }
}
