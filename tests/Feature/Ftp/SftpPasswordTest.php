<?php

namespace Tests\Feature\Ftp;

use App\Models\AuditLog;
use App\Models\Task;
use App\Models\User;
use App\Services\SftpService;
use App\Support\CryptPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SftpPasswordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_setting_the_password_only_sends_its_hash()
    {
        $this->freezeSecond();
        $account = User::factory()->create(['username' => 'budi']);

        $this->actingAs($account)
            ->put(route('sftp-password.update'), ['password' => 'a-long-sftp-password'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $task = Task::query()->sole();
        $this->assertSame('account.set_password', $task->action);
        $this->assertSame('budi', $task->payload['username']);
        $this->assertStringStartsWith('$6$', $task->payload['password_hash']);
        $this->assertTrue(CryptPassword::matches('a-long-sftp-password', $task->payload['password_hash']));
        $this->assertStringNotContainsString('a-long-sftp-password', json_encode($task->payload));

        $this->assertTrue($account->fresh()->sftp_password_set_at->equalTo(now()));
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'account.sftp_password_set', 'actor_id' => $account->id]);
    }

    public function test_the_password_needs_twelve_characters()
    {
        $account = User::factory()->create();

        $this->actingAs($account)
            ->put(route('sftp-password.update'), ['password' => 'too-short'])
            ->assertSessionHasErrors('password');

        $this->assertDatabaseCount('tasks', 0);
        $this->assertNull($account->fresh()->sftp_password_set_at);
    }

    public function test_removing_the_password_locks_it_on_the_server()
    {
        $account = User::factory()->create(['username' => 'budi', 'sftp_password_set_at' => now()]);

        $this->actingAs($account)
            ->delete(route('sftp-password.destroy'))
            ->assertRedirect();

        $this->assertSame(['username' => 'budi', 'password_hash' => ''], Task::query()->sole()->payload);
        $this->assertNull($account->fresh()->sftp_password_set_at);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'account.sftp_password_removed']);
    }

    public function test_only_active_accounts_can_set_it()
    {
        $suspended = User::factory()->suspended()->create();

        $this->expectException(ValidationException::class);

        try {
            app(SftpService::class)->setPassword($suspended, 'a-long-sftp-password', $suspended);
        } finally {
            $this->assertDatabaseCount('tasks', 0);
        }
    }

    public function test_admins_have_no_sftp_password_here()
    {
        $this->actingAs(User::factory()->admin()->create())
            ->put(route('sftp-password.update'), ['password' => 'a-long-sftp-password'])
            ->assertForbidden();
    }

    public function test_the_ftp_page_shows_how_to_use_sftp()
    {
        $account = User::factory()->create(['username' => 'budi']);

        $this->actingAs($account)
            ->get(route('ftp.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('sftp.username', 'budi')
                ->where('sftp.port', 22)
                ->where('sftp.password_set_at', null)
                ->where('sftp.pending', false)
                ->where('sftp.error', null));

        $this->actingAs($account)->put(route('sftp-password.update'), ['password' => 'a-long-sftp-password']);

        $this->actingAs($account)
            ->get(route('ftp.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->whereNot('sftp.password_set_at', null)
                ->where('sftp.pending', true));
    }
}
