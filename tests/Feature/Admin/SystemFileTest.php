<?php

namespace Tests\Feature\Admin;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class SystemFileTest extends TestCase
{
    use RefreshDatabase;

    private const FILES = ['files' => [
        ['id' => 'nginx-http', 'path' => '/etc/nginx/conf.d/rayxo-custom.conf'],
        ['id' => 'php-8.4', 'path' => '/etc/rayxo/php/8.4/99-custom.ini'],
        ['id' => 'php-fpm-pool', 'path' => '/etc/rayxo/php-fpm-pool.conf'],
    ]];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_admins_see_the_editable_files_and_open_one()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('call')->with('system.files', [], 120)->andReturn(self::FILES)
            ->shouldReceive('call')->with('system.file_read', ['file' => 'php-8.4'], 120)->andReturn([
                'content' => "memory_limit = 512M\n",
                'hash' => 'abc',
                'backups' => [['id' => '1763020800000000000', 'size' => 10, 'created_at' => '2026-11-13T08:00:00Z']],
            ]));

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.system-files.index', ['file' => 'php-8.4']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/system-files/index')
                ->has('files', 3)
                ->where('file.id', 'php-8.4')
                ->where('file.path', '/etc/rayxo/php/8.4/99-custom.ini')
                ->where('file.content', "memory_limit = 512M\n")
                ->where('file.hash', 'abc')
                ->where('file.backups.0.id', '1763020800000000000'));
    }

    public function test_an_unknown_file_opens_nothing_and_an_unreachable_agent_shows_no_files()
    {
        $admin = User::factory()->admin()->create();
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('call')->with('system.files', [], 120)->andReturn(self::FILES)->once()
            ->shouldReceive('call')->with('system.files', [], 120)->andThrow(new AgentException('unreachable')));

        $this->actingAs($admin)
            ->get(route('admin.system-files.index', ['file' => '../../etc/shadow']))
            ->assertInertia(fn (Assert $page) => $page->where('file', null));

        $this->actingAs($admin)
            ->get(route('admin.system-files.index'))
            ->assertInertia(fn (Assert $page) => $page->where('files', null)->where('file', null));
    }

    public function test_saving_a_file_hands_it_to_the_agent_and_records_it()
    {
        $admin = User::factory()->admin()->create();
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('call')->once()->with('system.file_write', ['file' => 'nginx-http', 'content' => 'gzip on;', 'hash' => 'abc'], 120)->andReturn(['hash' => 'def']));

        $this->actingAs($admin)
            ->put(route('admin.system-files.update'), ['file' => 'nginx-http', 'content' => "gzip on;\n", 'hash' => 'abc'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.system-files.index', ['file' => 'nginx-http']));

        $log = AuditLog::query()->where('action', 'system.file_changed')->sole();
        $this->assertSame(['file' => 'nginx-http', 'hash' => 'def'], $log->changes);
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_a_new_pool_template_configures_every_website_again()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('call')->with('system.file_write', Mockery::any(), 120)->andReturn(['hash' => 'def']));
        $website = Domain::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('admin.system-files.update'), ['file' => 'php-fpm-pool', 'content' => "pm.max_children = 4\n", 'hash' => 'abc'])
            ->assertSessionHasNoErrors();

        $this->assertSame([$website->name], Task::query()->where('action', 'web.apply_domain')->get()->pluck('payload.domain')->all());
    }

    public function test_the_agents_reason_for_refusing_a_change_is_shown()
    {
        $admin = User::factory()->admin()->create();
        $cases = [
            ['invalid', 'nginx rejected the change: unknown directive "gzpi"', 'The change was not saved: nginx rejected the change: unknown directive "gzpi"'],
            ['changed', 'the file changed since it was opened; open it again', 'The file changed since you opened it. Open it again to see the change.'],
            [null, 'socket closed', 'The file could not be saved. Try again in a moment.'],
        ];

        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('call')->times(3)->andThrowExceptions(array_map(
                fn (array $case): AgentException => new AgentException($case[1], 1, reason: $case[0]),
                $cases,
            )));

        foreach ($cases as [, , $shown]) {
            $this->actingAs($admin)
                ->put(route('admin.system-files.update'), ['file' => 'nginx-http', 'content' => "gzpi on;\n", 'hash' => 'abc'])
                ->assertSessionHasErrors(['content' => $shown]);
        }

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_a_backup_is_put_back()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('call')->once()->with('system.file_restore', ['file' => 'mariadb', 'backup' => '1763020800000000000', 'hash' => 'abc'], 120)->andReturn(['hash' => 'old']));

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.system-files.restore'), ['file' => 'mariadb', 'backup' => '1763020800000000000', 'hash' => 'abc'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas(AuditLog::class, ['action' => 'system.file_restored']);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.system-files.restore'), ['file' => 'mariadb', 'backup' => '../x', 'hash' => 'abc'])
            ->assertSessionHasErrors('backup');
    }

    public function test_only_administrators_edit_server_files()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('call'));

        foreach ([User::factory()->create(), User::factory()->reseller()->create()] as $user) {
            $this->actingAs($user)->get(route('admin.system-files.index'))->assertForbidden();
            $this->actingAs($user)->put(route('admin.system-files.update'), ['file' => 'nginx-http', 'content' => 'x', 'hash' => 'abc'])->assertForbidden();
        }
    }
}
