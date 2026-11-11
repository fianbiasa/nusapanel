<?php

namespace Tests\Feature\Console;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Enums\AccountStatus;
use App\Models\AccountUsage;
use App\Models\Database;
use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\MailDomain;
use App\Models\Package;
use App\Models\User;
use App\Services\QuotaService;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Mockery\MockInterface;
use Tests\TestCase;

class CollectUsageCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_the_usage_of_the_hosting_accounts_the_server_reports()
    {
        $this->freezeSecond();

        $budi = User::factory()->create(['username' => 'budi']);
        $sari = User::factory()->suspended()->create(['username' => 'sari']);
        $siti = User::factory()->create(['username' => 'siti']);
        User::factory()->create(['username' => 'rudi']);
        User::factory()->admin()->create(['username' => 'root1']);

        AccountUsage::factory()->for($budi, 'account')->create(['disk_kb' => 1, 'inodes' => 1, 'measured_at' => now()->subHour()]);
        $untouched = AccountUsage::factory()->for($siti, 'account')->create(['disk_kb' => 2048, 'inodes' => 20, 'measured_at' => now()->subHour()]);

        $this->agentReports([
            'enabled' => true,
            'filesystem' => '/',
            'accounts' => [
                $this->reportedAccount('budi', diskKb: 122_880, inodes: 1234),
                $this->reportedAccount('sari', diskKb: 0, inodes: 3),
                $this->reportedAccount('ghost', diskKb: 512, inodes: 5),
                $this->reportedAccount('root1', diskKb: 512, inodes: 5),
                $this->reportedAccount('rudi', diskKb: -5, inodes: 5),
            ],
        ]);

        $this->artisan('rayxo:collect-usage')
            ->expectsOutputToContain('Disk usage of 2 accounts stored.')
            ->assertSuccessful();

        $this->assertDatabaseCount('account_usage', 3);
        $this->assertDatabaseHas('account_usage', ['user_id' => $budi->id, 'disk_kb' => 122_880, 'inodes' => 1234, 'measured_at' => now()]);
        $this->assertDatabaseHas('account_usage', ['user_id' => $sari->id, 'disk_kb' => 0, 'inodes' => 3, 'measured_at' => now()]);
        $this->assertTrue($untouched->fresh()->measured_at->eq(now()->subHour()));
        $this->assertSame(2048, $untouched->fresh()->disk_kb);
        $this->assertTrue(QuotaService::isEnabled());
    }

    public function test_it_stores_the_size_of_every_database()
    {
        $account = User::factory()->create(['username' => 'budi']);
        $shop = Database::factory()->for($account, 'account')->create(['name' => 'budi_shop']);
        $empty = Database::factory()->for($account, 'account')->create(['name' => 'budi_empty']);
        $unmeasured = Database::factory()->for($account, 'account')->pending()->create(['name' => 'budi_new']);

        $this->agentReports(['enabled' => true, 'filesystem' => '/', 'accounts' => []], [
            ['name' => 'budi_shop', 'size_bytes' => 1048576],
            ['name' => 'budi_empty', 'size_bytes' => 0],
            ['name' => 'sari_other', 'size_bytes' => 4096],
        ]);

        $this->artisan('rayxo:collect-usage')
            ->expectsOutputToContain('Size of 2 databases stored.')
            ->assertSuccessful();

        $this->assertSame(1048576, $shop->fresh()->size_bytes);
        $this->assertSame(0, $empty->fresh()->size_bytes);
        $this->assertNotNull($shop->fresh()->measured_at);
        $this->assertNull($unmeasured->fresh()->size_bytes);
    }

    public function test_it_stores_the_size_of_every_email_account()
    {
        $domain = Domain::factory()->for(User::factory()->create(['username' => 'budi']), 'account')->create(['name' => 'example.com']);
        $mailDomain = MailDomain::factory()->for($domain)->create();
        $info = Mailbox::factory()->for($mailDomain)->create(['local_part' => 'info']);
        $unmeasured = Mailbox::factory()->for($mailDomain)->create(['local_part' => 'sales']);

        $this->agentReports(['enabled' => true, 'filesystem' => '/', 'accounts' => []], [], [
            ['address' => 'info@example.com', 'used_kb' => 9, 'messages' => 2],
            ['address' => 'gone@example.org', 'used_kb' => 100, 'messages' => 5],
        ]);

        $this->artisan('rayxo:collect-usage')
            ->expectsOutputToContain('Size of 1 email account stored.')
            ->assertSuccessful();

        $this->assertSame([9, 2], [$info->fresh()->used_kb, $info->fresh()->message_count]);
        $this->assertNull($unmeasured->fresh()->used_kb);
    }

    public function test_it_remembers_that_the_server_does_not_enforce_quotas()
    {
        Cache::forever(QuotaService::ENABLED_CACHE_KEY, true);
        User::factory()->create(['username' => 'budi']);

        $this->agentReports(['enabled' => false, 'filesystem' => '/', 'accounts' => []]);

        $this->artisan('rayxo:collect-usage')
            ->expectsOutputToContain('Disk quotas are not enabled on this server')
            ->assertSuccessful();

        $this->assertFalse(QuotaService::isEnabled());
        $this->assertDatabaseCount('account_usage', 0);
    }

    public function test_an_unreachable_agent_fails_the_command_and_changes_nothing()
    {
        Exceptions::fake();
        Cache::forever(QuotaService::ENABLED_CACHE_KEY, true);
        $usage = AccountUsage::factory()->for(User::factory()->create(['username' => 'budi']), 'account')
            ->create(['disk_kb' => 2048, 'inodes' => 20, 'measured_at' => now()->subHour()]);

        $this->mock(AgentClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('call')
                ->once()
                ->with('quota.report', [], config('agent.timeout'))
                ->andThrow(new AgentException('Connection refused'));
            $mock->shouldReceive('call')
                ->once()
                ->with('db.report', [], config('agent.timeout'))
                ->andThrow(new AgentException('Connection refused'));
            $mock->shouldReceive('call')
                ->once()
                ->with('mail.usage', [], config('agent.timeout'))
                ->andThrow(new AgentException('Connection refused'));
        });

        $this->artisan('rayxo:collect-usage')
            ->expectsOutputToContain('Connection refused')
            ->assertFailed();

        $this->assertSame(2048, $usage->fresh()->disk_kb);
        $this->assertTrue($usage->fresh()->measured_at->eq($usage->measured_at));
        $this->assertTrue(QuotaService::isEnabled());
        Exceptions::assertReported(AgentException::class);
    }

    public function test_the_simulated_agent_reports_usage_within_the_package_for_local_development()
    {
        $package = Package::factory()->create(['disk_mb' => 1024, 'inodes' => 50000]);
        $budi = User::factory()->create(['username' => 'budi', 'package_id' => $package->id]);
        User::factory()->create(['status' => AccountStatus::Pending]);

        $this->artisan('rayxo:collect-usage')
            ->expectsOutputToContain('Disk usage of 1 account stored.')
            ->assertSuccessful();

        $usage = AccountUsage::query()->sole();

        $this->assertSame($budi->id, $usage->user_id);
        $this->assertGreaterThan(0, $usage->disk_kb);
        $this->assertLessThanOrEqual(1024 * 1024, $usage->disk_kb);
        $this->assertLessThanOrEqual(50000, $usage->inodes);
        $this->assertTrue(QuotaService::isEnabled());
    }

    public function test_it_runs_every_fifteen_minutes_without_overlapping()
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains((string) $event->command, 'rayxo:collect-usage'));

        $this->assertNotNull($event);
        $this->assertSame('*/15 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  list<array{name: string, size_bytes: int}>  $databases
     * @param  list<array{address: string, used_kb: int, messages: int}>  $mailboxes
     */
    private function agentReports(array $report, array $databases = [], array $mailboxes = []): void
    {
        $this->mock(AgentClient::class, function (MockInterface $mock) use ($report, $databases, $mailboxes) {
            $mock->shouldReceive('call')
                ->once()
                ->with('mail.usage', [], config('agent.timeout'))
                ->andReturn(['mailboxes' => $mailboxes]);
            $mock->shouldReceive('call')
                ->once()
                ->with('quota.report', [], config('agent.timeout'))
                ->andReturn($report);
            $mock->shouldReceive('call')
                ->once()
                ->with('db.report', [], config('agent.timeout'))
                ->andReturn(['databases' => $databases]);
        });
    }

    /**
     * @return array<string, int|string>
     */
    private function reportedAccount(string $username, int $diskKb, int $inodes): array
    {
        return [
            'username' => $username,
            'uid' => 1001,
            'disk_kb' => $diskKb,
            'inodes' => $inodes,
            'disk_limit_kb' => 1_048_576,
            'inode_limit' => 50_000,
        ];
    }
}
