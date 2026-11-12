<?php

namespace Tests\Feature\Console;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Enums\AccountStatus;
use App\Models\BandwidthUsage;
use App\Models\Domain;
use App\Models\FtpAccount;
use App\Models\Package;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use App\Services\BandwidthService;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class CollectBandwidthCommandTest extends TestCase
{
    use RefreshDatabase;

    private const CURSOR = ['/var/log/mail.log' => ['inode' => 7, 'offset' => 99]];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->travelTo('2026-11-12 10:00:00');
    }

    public function test_it_adds_the_traffic_up_per_account_and_website_and_keeps_the_cursor()
    {
        $account = User::factory()->create();
        $domain = Domain::factory()->for($account, 'account')->create(['name' => 'example.com']);
        $ftp = FtpAccount::factory()->for($account, 'account')->for($domain)->create(['login' => 'up@example.com']);
        BandwidthUsage::factory()->for($account, 'account')->create(['date' => '2026-11-12', 'web_bytes' => 5]);

        $this->agentCounts([[
            'web' => [
                ['domain' => 'example.com', 'date' => '2026-11-11', 'bytes' => 100],
                ['domain' => 'example.com', 'date' => '2026-11-12', 'bytes' => 200],
                ['domain' => 'unknown.org', 'date' => '2026-11-12', 'bytes' => 999],
            ],
            'mail' => [['domain' => 'example.com', 'date' => '2026-11-12', 'bytes' => 30]],
            'ftp' => [
                ['user' => $ftp->login, 'date' => '2026-11-12', 'bytes' => 4],
                ['user' => 'stranger', 'date' => '2026-11-12', 'bytes' => 999],
            ],
            'cursor' => self::CURSOR,
            'complete' => true,
        ]]);

        $this->artisan('rayxo:collect-bandwidth')->assertSuccessful();

        $this->assertDatabaseHas('bandwidth_usages', ['user_id' => $account->id, 'date' => '2026-11-11', 'web_bytes' => 100, 'mail_bytes' => 0, 'ftp_bytes' => 0]);
        $this->assertDatabaseHas('bandwidth_usages', ['user_id' => $account->id, 'date' => '2026-11-12', 'web_bytes' => 205, 'mail_bytes' => 30, 'ftp_bytes' => 4]);
        $this->assertDatabaseCount('bandwidth_usages', 2);
        $this->assertDatabaseHas('domain_bandwidths', ['domain_id' => $domain->id, 'date' => '2026-11-12', 'bytes' => 230]);
        $this->assertSame(self::CURSOR, Setting::query()->where('key', BandwidthService::CURSOR_SETTING)->value('value'));
        $this->assertSame(339, BandwidthService::monthBytes($account));
    }

    public function test_the_next_count_starts_from_the_kept_cursor_and_goes_on_while_logs_are_left()
    {
        Setting::query()->create(['key' => BandwidthService::CURSOR_SETTING, 'value' => self::CURSOR]);
        $later = ['/var/log/mail.log' => ['inode' => 7, 'offset' => 500]];

        $this->mock(AgentClient::class, function (MockInterface $mock) use ($later) {
            $mock->shouldReceive('call')->once()
                ->with('usage.bandwidth', ['cursor' => self::CURSOR], config('agent.timeout'))
                ->andReturn(['web' => [], 'mail' => [], 'ftp' => [], 'cursor' => $later, 'complete' => false]);
            $mock->shouldReceive('call')->once()
                ->with('usage.bandwidth', ['cursor' => $later], config('agent.timeout'))
                ->andReturn(['web' => [], 'mail' => [], 'ftp' => [], 'cursor' => $later, 'complete' => true]);
        });

        $this->artisan('rayxo:collect-bandwidth')->assertSuccessful();
    }

    public function test_an_unreachable_agent_changes_nothing()
    {
        Exceptions::fake();
        Setting::query()->create(['key' => BandwidthService::CURSOR_SETTING, 'value' => self::CURSOR]);

        $this->mock(AgentClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('call')->once()->andThrow(new AgentException('Connection refused'));
        });

        $this->artisan('rayxo:collect-bandwidth')
            ->expectsOutputToContain('Connection refused')
            ->assertFailed();

        $this->assertSame(self::CURSOR, Setting::query()->where('key', BandwidthService::CURSOR_SETTING)->value('value'));
        $this->assertDatabaseCount('bandwidth_usages', 0);
        Exceptions::assertReported(AgentException::class);
    }

    public function test_accounts_over_their_bandwidth_are_suspended_when_that_is_turned_on()
    {
        $package = Package::factory()->create(['bandwidth_mb' => 1]);
        $over = User::factory()->create(['package_id' => $package->id]);
        $within = User::factory()->create(['package_id' => $package->id]);
        BandwidthUsage::factory()->for($over, 'account')->create(['date' => '2026-11-03', 'web_bytes' => 1048577]);
        BandwidthUsage::factory()->for($within, 'account')->create(['date' => '2026-11-03', 'web_bytes' => 1048576]);
        // Last month does not count.
        BandwidthUsage::factory()->for($within, 'account')->create(['date' => '2026-10-31', 'web_bytes' => 9999999]);

        $this->agentCounts([[]]);
        $this->artisan('rayxo:collect-bandwidth')->assertSuccessful();
        $this->assertSame(AccountStatus::Active, $over->fresh()->status);

        Setting::query()->create(['key' => BandwidthService::SUSPEND_SETTING, 'value' => true]);

        $this->agentCounts([[]]);
        $this->artisan('rayxo:collect-bandwidth')->assertSuccessful();

        $over->refresh();
        $this->assertSame(AccountStatus::Suspended, $over->status);
        $this->assertSame('The bandwidth of the package for this month is used up.', $over->suspend_reason);
        $this->assertNotNull($over->bandwidth_suspended_at);
        $this->assertSame('account.suspend', Task::query()->sole()->action);
        $this->assertSame(AccountStatus::Active, $within->fresh()->status);
    }

    public function test_a_bandwidth_suspension_is_lifted_when_a_new_month_starts()
    {
        $account = User::factory()->suspended('Bandwidth')->create(['bandwidth_suspended_at' => '2026-10-20 08:00:00']);
        $thisMonth = User::factory()->suspended('Bandwidth')->create(['bandwidth_suspended_at' => '2026-11-02 08:00:00']);

        $this->agentCounts([[]]);
        $this->artisan('rayxo:collect-bandwidth')->assertSuccessful();

        $account->refresh();
        $this->assertSame(AccountStatus::Active, $account->status);
        $this->assertNull($account->bandwidth_suspended_at);
        $this->assertSame('account.unsuspend', Task::query()->sole()->action);
        $this->assertSame(AccountStatus::Suspended, $thisMonth->fresh()->status);
    }

    public function test_it_runs_every_fifteen_minutes()
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains((string) $event->command, 'rayxo:collect-bandwidth'));

        $this->assertNotNull($event);
        $this->assertSame('*/15 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    /**
     * The agent answers each count in turn; an empty report is a count
     * that found nothing.
     *
     * @param  list<array<string, mixed>>  $reports
     */
    private function agentCounts(array $reports): void
    {
        $this->mock(AgentClient::class, function (MockInterface $mock) use ($reports) {
            foreach ($reports as $report) {
                $mock->shouldReceive('call')->once()
                    ->with('usage.bandwidth', \Mockery::any(), config('agent.timeout'))
                    ->andReturn($report === [] ? ['web' => [], 'mail' => [], 'ftp' => [], 'cursor' => [], 'complete' => true] : $report);
            }
        });
    }
}
