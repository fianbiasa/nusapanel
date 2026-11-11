<?php

namespace Tests\Feature\Ftp;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Enums\FtpAccountStatus;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\FtpAccount;
use App\Models\Task;
use App\Models\User;
use App\Services\FtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FtpQuotaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_a_login_with_a_quota_has_its_folder_counted_and_pure_ftpd_reads_the_quota()
    {
        $account = User::factory()->create(['username' => 'budi', 'uid' => 1001, 'gid' => 1001]);
        $domain = Domain::factory()->for($account, 'account')->create(['name' => 'budi.test']);

        $this->actingAs($account)
            ->post(route('ftp.store'), [
                'name' => 'dev',
                'domain_id' => $domain->id,
                'directory' => 'uploads',
                'password' => 'a-long-ftp-password',
                'quota_mb' => '500',
            ])
            ->assertSessionHasNoErrors();

        $ftpAccount = FtpAccount::query()->sole();
        $this->assertSame(500, $ftpAccount->quota_mb);
        $this->assertSame(['username' => 'budi', 'directory' => 'uploads', 'quota' => true], Task::query()->sole()->payload);

        $ftpAccount->forceFill(['status' => FtpAccountStatus::Active])->save();
        FtpAccount::factory()->for($account, 'account')->for($domain)->create(['login' => 'all@budi.test', 'directory' => '']);

        $quotas = DB::table('v_ftp_users')->orderBy('login')->pluck('quota_size', 'login')
            ->map(fn (mixed $size): ?int => $size === null ? null : (int) $size)
            ->all();
        $this->assertSame(['all@budi.test' => null, 'dev@budi.test' => 500], $quotas);

        $this->actingAs($account)
            ->get(route('ftp.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('ftp_accounts.0.quota_mb', null)
                ->where('ftp_accounts.1.quota_mb', 500)
                ->where('max_quota_mb', FtpService::MAX_QUOTA_MB));
    }

    public function test_a_new_quota_counts_the_folder_while_the_login_keeps_working()
    {
        $account = User::factory()->create(['username' => 'budi']);
        $ftpAccount = FtpAccount::factory()->for($account, 'account')->create(['directory' => 'uploads']);

        $this->actingAs($account)
            ->put(route('ftp.update', $ftpAccount), ['directory' => 'uploads', 'quota_mb' => 200])
            ->assertSessionHasNoErrors();

        $ftpAccount->refresh();
        $this->assertSame(200, $ftpAccount->quota_mb);
        $this->assertSame(FtpAccountStatus::Active, $ftpAccount->status);
        $this->assertSame(['username' => 'budi', 'directory' => 'uploads', 'quota' => true], Task::query()->sole()->payload);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'ftp_account.updated', 'subject_id' => $ftpAccount->id]);

        // Removing the quota needs nothing from the server.
        $this->actingAs($account)
            ->put(route('ftp.update', $ftpAccount), ['directory' => 'uploads', 'quota_mb' => ''])
            ->assertSessionHasNoErrors();

        $this->assertNull($ftpAccount->fresh()->quota_mb);
        $this->assertDatabaseCount('tasks', 1);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidQuotas(): array
    {
        return [
            'zero' => [0],
            'negative' => [-5],
            'fraction' => ['1.5'],
            'text' => ['banyak'],
            'above 1 TB' => [FtpService::MAX_QUOTA_MB + 1],
        ];
    }

    #[DataProvider('invalidQuotas')]
    public function test_a_quota_is_a_whole_number_of_megabytes_up_to_1_tb(mixed $quota)
    {
        $account = User::factory()->create();
        $domain = Domain::factory()->for($account, 'account')->create();
        $ftpAccount = FtpAccount::factory()->for($account, 'account')->for($domain)->create();

        $this->actingAs($account)
            ->post(route('ftp.store'), [
                'name' => 'dev',
                'domain_id' => $domain->id,
                'directory' => 'uploads',
                'password' => 'a-long-ftp-password',
                'quota_mb' => $quota,
            ])
            ->assertSessionHasErrors('quota_mb');

        $this->actingAs($account)
            ->put(route('ftp.update', $ftpAccount), ['directory' => $ftpAccount->directory, 'quota_mb' => $quota])
            ->assertSessionHasErrors('quota_mb');

        $this->assertDatabaseCount(FtpAccount::class, 1);
        $this->assertNull($ftpAccount->fresh()->quota_mb);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_the_nightly_recount_counts_each_ready_folder_with_a_quota_once()
    {
        $budi = User::factory()->create(['username' => 'budi']);
        $domain = Domain::factory()->for($budi, 'account')->create(['name' => 'budi.test']);
        FtpAccount::factory()->for($budi, 'account')->for($domain)->create(['directory' => 'uploads', 'quota_mb' => 100]);
        FtpAccount::factory()->for($budi, 'account')->for($domain)->create(['directory' => 'uploads', 'quota_mb' => 300]);
        FtpAccount::factory()->for($budi, 'account')->for($domain)->create(['directory' => '', 'quota_mb' => 50]);
        FtpAccount::factory()->for($budi, 'account')->for($domain)->create(['directory' => 'free']);
        FtpAccount::factory()->for($budi, 'account')->for($domain)->pending()->create(['directory' => 'new', 'quota_mb' => 10]);
        FtpAccount::factory()->for(User::factory()->suspended()->create(), 'account')->create(['quota_mb' => 10]);

        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')
            ->once()
            ->with('ftp.ensure_dir', ['username' => 'budi', 'directory' => '', 'quota' => true], Mockery::type('int'))
            ->andReturn(['uid' => 1001, 'gid' => 1001, 'home' => '/home/budi']);
        $agent->shouldReceive('call')
            ->once()
            ->with('ftp.ensure_dir', ['username' => 'budi', 'directory' => 'uploads', 'quota' => true], Mockery::type('int'))
            ->andThrow(new AgentException('could not count the files of /home/budi/uploads for its FTP quota'));
        $this->app->instance(AgentClient::class, $agent);

        $this->artisan('rayxo:recount-ftp-quotas')
            ->expectsOutputToContain('1 folder counted.')
            ->expectsOutputToContain('1 folder could not be counted.')
            ->assertFailed();

        $this->assertDatabaseCount('tasks', 0);
    }
}
