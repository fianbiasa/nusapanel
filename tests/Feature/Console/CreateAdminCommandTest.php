<?php

namespace Tests\Feature\Console;

use App\Enums\AccountStatus;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_active_verified_admin()
    {
        $this->artisan('nusapanel:create-admin', [
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
        putenv('NUSAPANEL_ADMIN_PASSWORD=env-Strong-pass-456');

        try {
            $this->artisan('nusapanel:create-admin', [
                'username' => 'fian',
                'email' => 'fian@example.com',
            ])
                ->doesntExpectOutputToContain('Generated password')
                ->assertSuccessful();
        } finally {
            putenv('NUSAPANEL_ADMIN_PASSWORD');
        }

        $admin = User::query()->where('username', 'fian')->sole();

        $this->assertTrue(Hash::check('env-Strong-pass-456', $admin->password));
    }

    public function test_it_generates_a_password_when_none_is_given()
    {
        $this->artisan('nusapanel:create-admin', [
            'username' => 'fian',
            'email' => 'fian@example.com',
        ])
            ->expectsOutputToContain('Generated password (shown only once)')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['username' => 'fian', 'role' => 'admin']);
    }

    public function test_it_rejects_reserved_and_invalid_usernames()
    {
        foreach (['root', 'mysql', 'Admin', 'ab', 'www-data', '1admin'] as $username) {
            $this->artisan('nusapanel:create-admin', [
                'username' => $username,
                'email' => 'someone@example.com',
                '--password' => 'a-Strong-pass-123',
            ])->assertFailed();
        }

        $this->assertDatabaseCount('users', 0);
    }
}
