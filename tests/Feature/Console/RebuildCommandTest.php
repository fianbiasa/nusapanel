<?php

namespace Tests\Feature\Console;

use App\Enums\AccountStatus;
use App\Models\CronJob;
use App\Models\FtpAccount;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RebuildCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_queues_every_config_of_the_accounts_that_exist_on_the_server()
    {
        Queue::fake();

        $budi = User::factory()->create(['username' => 'budi']);
        $budiFtp = FtpAccount::factory()->for($budi, 'account')->create();
        CronJob::factory()->for($budi, 'account')->create(['schedule' => '@daily', 'command' => 'php backup.php']);
        CronJob::factory()->for($budi, 'account')->create(['enabled' => false]);
        CronJob::factory()->for($budi, 'account')->create(['schedule' => '*/5 * * * *', 'command' => 'php queue.php']);

        $sari = User::factory()->suspended()->create(['username' => 'sari']);
        $sariFtp = FtpAccount::factory()->for($sari, 'account')->create();

        $leaving = User::factory()->create(['status' => AccountStatus::Deleting]);
        FtpAccount::factory()->for($leaving, 'account')->create();
        CronJob::factory()->for($leaving, 'account')->create();
        User::factory()->admin()->create();

        $this->artisan('rayxo:rebuild')
            ->expectsOutputToContain('Queued: 2 websites, 2 disk quotas, 2 crontabs, 2 FTP folders, 2 DNS zones, 0 mail domains.')
            ->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            ['budi', 'sari'],
            Task::query()->where('action', 'account.set_quota')->get()->pluck('payload.username')->all(),
        );
        $this->assertSame(2, Task::query()->where('action', 'web.apply_domain')->count());
        $this->assertEqualsCanonicalizing(['budi', 'sari'], Task::query()->where('action', 'dns.sync_zone')->get()->pluck('payload.username')->all());

        $crontabs = Task::query()
            ->where('action', 'cron.apply')
            ->get()
            ->mapWithKeys(fn (Task $task): array => [$task->payload['username'] => $task->payload['jobs']]);
        $this->assertSame([
            'budi' => [
                ['schedule' => '@daily', 'command' => 'php backup.php'],
                ['schedule' => '*/5 * * * *', 'command' => 'php queue.php'],
            ],
            'sari' => [],
        ], $crontabs->sortKeys()->all());

        $this->assertEqualsCanonicalizing([
            ['username' => 'budi', 'directory' => $budiFtp->directory],
            ['username' => 'sari', 'directory' => $sariFtp->directory],
        ], Task::query()->where('action', 'ftp.ensure_dir')->get()->pluck('payload')->all());
    }
}
