<?php

namespace Tests\Feature\Ftp;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Enums\FtpAccountStatus;
use App\Jobs\RunAgentTask;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\FtpAccount;
use App\Models\Package;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FtpAccountManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_admins_cannot_reach_ftp()
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('ftp.index'))
            ->assertForbidden();
    }

    public function test_the_page_lists_the_accounts_logins_and_domains()
    {
        $account = User::factory()->create(['username' => 'budi']);
        $domain = Domain::factory()->for($account, 'account')->create(['name' => 'budi.test']);
        Domain::factory()->for($account, 'account')->subdomainOf($domain)->create();
        Domain::factory()->for($account, 'account')->pointerOf($domain)->create(['name' => 'pointer.test']);
        FtpAccount::factory()->for($account, 'account')->for($domain)->create(['login' => 'dev@budi.test', 'directory' => '']);
        FtpAccount::factory()->create();

        $this->actingAs($account)
            ->get(route('ftp.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ftp/index')
                ->has('ftp_accounts', 1)
                ->where('ftp_accounts.0.login', 'dev@budi.test')
                ->where('ftp_accounts.0.directory', '')
                ->missing('ftp_accounts.0.password_hash')
                ->where('domains', fn ($domains) => collect($domains)->pluck('name')->all() === ['blog.budi.test', 'budi.test'])
                ->where('home', '/home/budi')
                ->where('connection.port', 21));
    }

    public function test_adding_a_login_prepares_its_folder()
    {
        $account = User::factory()->create(['username' => 'budi']);
        $domain = Domain::factory()->for($account, 'account')->create(['name' => 'budi.test']);

        $this->actingAs($account)
            ->post(route('ftp.store'), [
                'name' => ' Dev ',
                'domain_id' => $domain->id,
                'directory' => '/domains/budi.test/public_html/',
                'password' => 'a-long-ftp-password',
            ])
            ->assertRedirect(route('ftp.index'))
            ->assertSessionHasNoErrors();

        $ftpAccount = FtpAccount::query()->sole();
        $this->assertSame('dev@budi.test', $ftpAccount->login);
        $this->assertSame('domains/budi.test/public_html', $ftpAccount->directory);
        $this->assertSame(FtpAccountStatus::Pending, $ftpAccount->status);
        $this->assertStringStartsWith('$6$', $ftpAccount->password_hash);
        $this->assertTrue($ftpAccount->passwordMatches('a-long-ftp-password'));
        $this->assertFalse($ftpAccount->passwordMatches('another-password'));

        $task = Task::query()->sole();
        $this->assertSame('ftp.ensure_dir', $task->action);
        $this->assertSame(['username' => 'budi', 'directory' => 'domains/budi.test/public_html'], $task->payload);

        $this->assertDatabaseHas(AuditLog::class, ['action' => 'ftp_account.created', 'actor_id' => $account->id]);
    }

    public function test_a_login_may_have_the_whole_home()
    {
        $account = User::factory()->create();
        $domain = Domain::factory()->for($account, 'account')->create();

        $this->actingAs($account)
            ->post(route('ftp.store'), ['name' => 'all', 'domain_id' => $domain->id, 'directory' => '/', 'password' => 'a-long-ftp-password'])
            ->assertSessionHasNoErrors();

        $this->assertSame('', FtpAccount::query()->sole()->directory);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function foldersOutsideTheHome(): array
    {
        return [
            'parent' => ['..'],
            'parent inside' => ['domains/../../etc'],
            'current' => ['domains/./x'],
            'empty part' => ['domains//x'],
            'space' => ['my files'],
            'shell' => ['x;rm -rf'],
            'too deep' => [str_repeat('a/', 16).'a'],
        ];
    }

    #[DataProvider('foldersOutsideTheHome')]
    public function test_folders_outside_the_home_are_refused(string $directory)
    {
        $account = User::factory()->create();
        $domain = Domain::factory()->for($account, 'account')->create();

        $this->actingAs($account)
            ->post(route('ftp.store'), ['name' => 'dev', 'domain_id' => $domain->id, 'directory' => $directory, 'password' => 'a-long-ftp-password'])
            ->assertSessionHasErrors('directory');

        $this->assertDatabaseCount(FtpAccount::class, 0);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_a_login_is_named_after_a_domain_of_the_account()
    {
        $account = User::factory()->create();
        $domain = Domain::factory()->for($account, 'account')->create();
        $pointer = Domain::factory()->for($account, 'account')->pointerOf($domain)->create();
        $otherAccountsDomain = Domain::factory()->create();

        foreach ([$pointer, $otherAccountsDomain] as $wrong) {
            $this->actingAs($account)
                ->post(route('ftp.store'), ['name' => 'dev', 'domain_id' => $wrong->id, 'directory' => '', 'password' => 'a-long-ftp-password'])
                ->assertSessionHasErrors(['domain_id' => 'Choose a domain of this account.']);
        }

        $this->assertDatabaseCount(FtpAccount::class, 0);
    }

    public function test_a_login_name_is_taken_once()
    {
        $account = User::factory()->create();
        $domain = Domain::factory()->for($account, 'account')->create(['name' => 'budi.test']);
        FtpAccount::factory()->for($account, 'account')->for($domain)->create(['login' => 'dev@budi.test']);

        $this->actingAs($account)
            ->post(route('ftp.store'), ['name' => 'dev', 'domain_id' => $domain->id, 'directory' => '', 'password' => 'a-long-ftp-password'])
            ->assertSessionHasErrors(['name' => 'This name is already in use.']);
    }

    public function test_logins_count_against_the_package_limit()
    {
        $account = User::factory()->create(['package_id' => Package::factory()->create(['ftp_accounts' => 1])->id]);
        $domain = Domain::factory()->for($account, 'account')->create();
        FtpAccount::factory()->for($account, 'account')->for($domain)->create();

        $this->actingAs($account)
            ->post(route('ftp.store'), ['name' => 'second', 'domain_id' => $domain->id, 'directory' => '', 'password' => 'a-long-ftp-password'])
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount(FtpAccount::class, 1);
    }

    public function test_a_new_password_needs_no_agent()
    {
        $ftpAccount = FtpAccount::factory()->create();
        $directory = $ftpAccount->directory;

        $this->actingAs($ftpAccount->account)
            ->put(route('ftp.update', $ftpAccount), ['password' => 'a-new-long-password', 'directory' => $directory])
            ->assertSessionHasNoErrors();

        $ftpAccount->refresh();
        $this->assertTrue($ftpAccount->passwordMatches('a-new-long-password'));
        $this->assertSame(FtpAccountStatus::Active, $ftpAccount->status);
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'ftp_account.updated']);
    }

    public function test_a_new_folder_is_prepared_and_the_password_kept()
    {
        $ftpAccount = FtpAccount::factory()->create();

        $this->actingAs($ftpAccount->account)
            ->put(route('ftp.update', $ftpAccount), ['password' => '', 'directory' => 'backups'])
            ->assertSessionHasNoErrors();

        $ftpAccount->refresh();
        $this->assertSame('backups', $ftpAccount->directory);
        $this->assertSame(FtpAccountStatus::Pending, $ftpAccount->status);
        $this->assertTrue($ftpAccount->passwordMatches('password'));
        $this->assertSame('ftp.ensure_dir', Task::query()->sole()->action);
    }

    public function test_deleting_a_login_takes_effect_at_once()
    {
        $ftpAccount = FtpAccount::factory()->create();

        $this->actingAs($ftpAccount->account)
            ->delete(route('ftp.destroy', $ftpAccount))
            ->assertRedirect();

        $this->assertModelMissing($ftpAccount);
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'ftp_account.deleted']);
    }

    public function test_other_accounts_logins_are_missing()
    {
        $ftpAccount = FtpAccount::factory()->create();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->put(route('ftp.update', $ftpAccount), ['password' => 'a-new-long-password', 'directory' => ''])
            ->assertNotFound();

        $this->actingAs($stranger)
            ->delete(route('ftp.destroy', $ftpAccount))
            ->assertNotFound();

        $this->assertModelExists($ftpAccount);
    }

    public function test_the_agent_readies_the_login_and_reports_the_accounts_ids()
    {
        $account = User::factory()->create(['username' => 'budi', 'uid' => 1001, 'gid' => null]);
        $domain = Domain::factory()->for($account, 'account')->create(['name' => 'budi.test']);

        $this->actingAs($account)->post(route('ftp.store'), [
            'name' => 'dev', 'domain_id' => $domain->id, 'directory' => 'uploads', 'password' => 'a-long-ftp-password',
        ]);

        $this->runTask(Task::query()->sole(), ['uid' => 1001, 'gid' => 1002, 'home' => '/home/budi']);

        $this->assertSame(FtpAccountStatus::Active, FtpAccount::query()->sole()->status);
        $this->assertSame(1002, $account->fresh()->gid);
    }

    public function test_a_folder_the_agent_could_not_make_fails_the_login()
    {
        $account = User::factory()->create();
        $domain = Domain::factory()->for($account, 'account')->create();

        $this->actingAs($account)->post(route('ftp.store'), [
            'name' => 'dev', 'domain_id' => $domain->id, 'directory' => 'uploads', 'password' => 'a-long-ftp-password',
        ]);

        $this->runTask(Task::query()->sole(), new AgentException('a file or link is in the way of folder uploads'));

        $this->assertSame(FtpAccountStatus::Failed, FtpAccount::query()->sole()->status);
    }

    public function test_pure_ftpd_only_sees_ready_logins_of_active_accounts()
    {
        $budi = User::factory()->create(['username' => 'budi', 'uid' => 1001, 'gid' => 1001]);
        $domain = Domain::factory()->for($budi, 'account')->create(['name' => 'budi.test']);
        FtpAccount::factory()->for($budi, 'account')->for($domain)->create(['login' => 'web@budi.test', 'directory' => 'domains/budi.test/public_html']);
        FtpAccount::factory()->for($budi, 'account')->for($domain)->create(['login' => 'all@budi.test', 'directory' => '']);
        FtpAccount::factory()->for($budi, 'account')->for($domain)->pending()->create(['login' => 'new@budi.test']);

        $suspended = User::factory()->suspended()->create(['uid' => 1002, 'gid' => 1002]);
        FtpAccount::factory()->for($suspended, 'account')->create();

        $withoutIds = User::factory()->create(['uid' => null, 'gid' => null]);
        FtpAccount::factory()->for($withoutIds, 'account')->create();

        $rows = DB::table('v_ftp_users')->orderBy('login')->get()->map(fn (object $row): array => (array) $row)->all();

        $this->assertCount(2, $rows);
        $this->assertSame(['all@budi.test', 1001, 1001, '/home/budi'], [$rows[0]['login'], (int) $rows[0]['uid'], (int) $rows[0]['gid'], $rows[0]['dir']]);
        $this->assertSame(['web@budi.test', '/home/budi/domains/budi.test/public_html'], [$rows[1]['login'], $rows[1]['dir']]);
        $this->assertStringStartsWith('$6$', $rows[1]['password']);
    }

    /**
     * Run the task against a mocked agent that returns output, or throws.
     *
     * @param  array<string, mixed>|AgentException  $result
     */
    private function runTask(Task $task, array|AgentException $result): void
    {
        $agent = Mockery::mock(AgentClient::class);
        $call = $agent->shouldReceive('call')->once();

        $result instanceof AgentException ? $call->andThrow($result) : $call->andReturn($result);

        (new RunAgentTask($task))->handle($agent);
    }
}
