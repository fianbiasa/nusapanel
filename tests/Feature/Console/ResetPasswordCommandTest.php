<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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

        $this->artisan('rayxo:reset-password', ['username' => 'admin', '--password' => 'new-Strong-pass-789'])
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

    public function test_it_generates_a_password_and_prints_it_exactly_as_it_is()
    {
        $user = User::factory()->admin()->create(['username' => 'admin']);
        $oldHash = $user->password;

        $this->assertSame(0, Artisan::call('rayxo:reset-password', ['username' => 'admin']));

        // What an admin copies from the terminal must be the password itself,
        // with nothing added (the console components add a full stop).
        $printed = $this->lineAfter('New password (shown only once):', Artisan::output());
        $this->assertNotSame($oldHash, $user->fresh()->password);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{12}$/', $printed);
        $this->assertTrue(Hash::check($printed, $user->fresh()->password));
    }

    public function test_a_generated_password_is_accepted_where_chosen_ones_need_symbols()
    {
        $user = User::factory()->admin()->create(['username' => 'admin']);
        $this->app['env'] = 'production';

        $this->artisan('rayxo:reset-password', ['username' => 'admin', '--password' => 'NoSymbols12345'])
            ->expectsOutputToContain('symbol')
            ->assertFailed();

        $this->assertFalse(Hash::check('NoSymbols12345', $user->fresh()->password));
        $this->assertSame(0, Artisan::call('rayxo:reset-password', ['username' => 'admin']));
        $this->assertTrue(Hash::check($this->lineAfter('New password (shown only once):', Artisan::output()), $user->fresh()->password));
    }

    private function lineAfter(string $label, string $output): string
    {
        $lines = preg_split('/\R/', $output);
        $labelAt = collect($lines)->search(fn (string $line): bool => str_contains($line, $label));
        $this->assertIsInt($labelAt, "no \"{$label}\" in the output");

        return (string) collect(array_slice($lines, $labelAt + 1))->first(fn (string $line): bool => trim($line) !== '');
    }

    public function test_it_fails_for_an_unknown_account()
    {
        $this->artisan('rayxo:reset-password', ['username' => 'ghost'])
            ->expectsOutputToContain('No account named [ghost]')
            ->assertFailed();
    }
}
