<?php

namespace Tests\Feature\Console;

use App\Enums\AccountStatus;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_active_verified_admin()
    {
        $this->artisan('rayxo:create-admin', [
            'username' => 'fian',
            'email' => 'Fian@Example.com',
            '--password' => 'a-Strong-pass-123',
        ])
            ->expectsOutputToContain('Admin account [fian] created.')
            ->assertSuccessful();

        $admin = User::query()->where('username', 'fian')->sole();

        $this->assertSame(Role::Admin, $admin->role);
        $this->assertSame(AccountStatus::Active, $admin->status);
        $this->assertSame('fian@example.com', $admin->email);
        $this->assertNotNull($admin->email_verified_at);
        $this->assertTrue(Hash::check('a-Strong-pass-123', $admin->password));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'account.created',
            'subject_id' => (string) $admin->id,
        ]);
    }

    public function test_it_reads_the_password_from_the_environment()
    {
        putenv('RAYXO_ADMIN_PASSWORD=env-Strong-pass-456');

        try {
            $this->artisan('rayxo:create-admin', [
                'username' => 'fian',
                'email' => 'fian@example.com',
            ])
                ->doesntExpectOutputToContain('Generated password')
                ->assertSuccessful();
        } finally {
            putenv('RAYXO_ADMIN_PASSWORD');
        }

        $admin = User::query()->where('username', 'fian')->sole();

        $this->assertTrue(Hash::check('env-Strong-pass-456', $admin->password));
    }

    public function test_it_generates_a_password_and_prints_it_exactly_as_it_is()
    {
        $this->assertSame(0, Artisan::call('rayxo:create-admin', [
            'username' => 'fian',
            'email' => 'fian@example.com',
        ]));

        // setup.sh shows the first non-empty line after the label as the
        // admin's password, so it must be the password with nothing added.
        $lines = preg_split('/\R/', Artisan::output());
        $labelAt = collect($lines)->search(fn (string $line): bool => str_contains($line, 'Generated password (shown only once):'));
        $this->assertIsInt($labelAt);
        $printed = (string) collect(array_slice($lines, $labelAt + 1))->first(fn (string $line): bool => trim($line) !== '');

        $admin = User::query()->where('username', 'fian')->sole();
        $this->assertSame(Role::Admin, $admin->role);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{12}$/', $printed);
        $this->assertTrue(Hash::check($printed, $admin->password));
    }

    public function test_a_generated_password_is_accepted_where_chosen_ones_need_symbols()
    {
        $this->app['env'] = 'production';

        $this->artisan('rayxo:create-admin', ['username' => 'nosym', 'email' => 'nosym@example.com', '--password' => 'NoSymbols12345'])
            ->expectsOutputToContain('symbol')
            ->assertFailed();

        $this->artisan('rayxo:create-admin', ['username' => 'fian', 'email' => 'fian@example.com'])
            ->expectsOutputToContain('Generated password (shown only once):')
            ->assertSuccessful();

        $this->assertDatabaseMissing('users', ['username' => 'nosym']);
        $this->assertDatabaseHas('users', ['username' => 'fian']);
    }

    public function test_it_rejects_reserved_and_invalid_usernames()
    {
        foreach (['root', 'mysql', 'Admin', 'ab', 'www-data', '1admin'] as $username) {
            $this->artisan('rayxo:create-admin', [
                'username' => $username,
                'email' => 'someone@example.com',
                '--password' => 'a-Strong-pass-123',
            ])->assertFailed();
        }

        $this->assertDatabaseCount('users', 0);
    }
}
