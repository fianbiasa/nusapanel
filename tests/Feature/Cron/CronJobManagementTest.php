<?php

namespace Tests\Feature\Cron;

use App\Enums\TaskStatus;
use App\Models\AuditLog;
use App\Models\CronJob;
use App\Models\Package;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CronJobManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_admins_cannot_reach_cron_jobs()
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('cron-jobs.index'))
            ->assertForbidden();
    }

    public function test_the_page_lists_the_accounts_jobs()
    {
        $account = User::factory()->create(['username' => 'budi']);
        CronJob::factory()->for($account, 'account')->create(['schedule' => '@daily', 'command' => 'php artisan backup']);
        CronJob::factory()->create();

        $this->actingAs($account)
            ->get(route('cron-jobs.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('cron-jobs/index')
                ->has('cron_jobs', 1)
                ->where('cron_jobs.0.schedule', '@daily')
                ->where('cron_jobs.0.command', 'php artisan backup')
                ->where('sync', ['pending' => false, 'error' => null])
                ->where('home', '/home/budi'));
    }

    public function test_adding_a_job_sends_the_whole_crontab()
    {
        $account = User::factory()->create(['username' => 'budi']);
        CronJob::factory()->for($account, 'account')->create(['schedule' => '@daily', 'command' => 'first']);
        CronJob::factory()->for($account, 'account')->disabled()->create(['command' => 'paused']);

        $this->actingAs($account)
            ->post(route('cron-jobs.store'), [
                'schedule' => '  */5   *  * * MON-fri ',
                'command' => ' php artisan schedule:run >> cron.log 2>&1 ',
                'description' => 'Scheduler',
            ])
            ->assertRedirect(route('cron-jobs.index'))
            ->assertSessionHasNoErrors();

        $job = CronJob::query()->latest('id')->first();
        $this->assertSame('*/5 * * * mon-fri', $job->schedule);
        $this->assertSame('php artisan schedule:run >> cron.log 2>&1', $job->command);
        $this->assertTrue($job->enabled);

        $task = Task::query()->sole();
        $this->assertSame('cron.apply', $task->action);
        $this->assertSame(['username' => 'budi', 'jobs' => [
            ['schedule' => '@daily', 'command' => 'first'],
            ['schedule' => '*/5 * * * mon-fri', 'command' => 'php artisan schedule:run >> cron.log 2>&1'],
        ]], $task->payload);

        $this->assertDatabaseHas(AuditLog::class, ['action' => 'cron_job.created', 'actor_id' => $account->id]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidSchedules(): array
    {
        return [
            'reboot' => ['@reboot'],
            'four fields' => ['* * * *'],
            'six fields' => ['* * * * * *'],
            'minute 60' => ['60 * * * *'],
            'step without range' => ['5/10 * * * *'],
            'backwards range' => ['10-5 * * * *'],
            'step zero' => ['*/0 * * * *'],
            'words' => ['every day'],
        ];
    }

    #[DataProvider('invalidSchedules')]
    public function test_invalid_schedules_are_refused(string $schedule)
    {
        $account = User::factory()->create();

        $this->actingAs($account)
            ->post(route('cron-jobs.store'), ['schedule' => $schedule, 'command' => 'true'])
            ->assertSessionHasErrors('schedule');

        $this->assertDatabaseCount(CronJob::class, 0);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_a_command_is_one_line()
    {
        $account = User::factory()->create();

        $this->actingAs($account)
            ->post(route('cron-jobs.store'), ['schedule' => '@daily', 'command' => "true\nMAILTO=evil@example.com"])
            ->assertSessionHasErrors(['command' => 'Write the command on one line.']);

        $this->assertDatabaseCount(CronJob::class, 0);
    }

    public function test_jobs_count_against_the_package_limit()
    {
        $account = User::factory()->create(['package_id' => Package::factory()->create(['cron_jobs' => 1])->id]);
        CronJob::factory()->for($account, 'account')->create();

        $this->actingAs($account)
            ->post(route('cron-jobs.store'), ['schedule' => '@daily', 'command' => 'true'])
            ->assertSessionHasErrors('schedule');

        $this->assertDatabaseCount(CronJob::class, 1);
    }

    public function test_a_paused_job_leaves_the_crontab()
    {
        $account = User::factory()->create(['username' => 'budi']);
        $job = CronJob::factory()->for($account, 'account')->create(['schedule' => '@daily', 'command' => 'backup']);

        $this->actingAs($account)
            ->put(route('cron-jobs.update', $job), ['schedule' => '@daily', 'command' => 'backup', 'enabled' => false])
            ->assertSessionHasNoErrors();

        $this->assertFalse($job->fresh()->enabled);
        $this->assertSame(['username' => 'budi', 'jobs' => []], Task::query()->sole()->payload);
    }

    public function test_deleting_a_job_sends_the_rest()
    {
        $account = User::factory()->create(['username' => 'budi']);
        $kept = CronJob::factory()->for($account, 'account')->create(['schedule' => '@hourly', 'command' => 'kept']);
        $deleted = CronJob::factory()->for($account, 'account')->create();

        $this->actingAs($account)
            ->delete(route('cron-jobs.destroy', $deleted))
            ->assertRedirect();

        $this->assertModelMissing($deleted);
        $this->assertModelExists($kept);
        $this->assertSame(['username' => 'budi', 'jobs' => [['schedule' => '@hourly', 'command' => 'kept']]], Task::query()->sole()->payload);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'cron_job.deleted']);
    }

    public function test_other_accounts_jobs_are_missing()
    {
        $job = CronJob::factory()->create();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->put(route('cron-jobs.update', $job), ['schedule' => '@daily', 'command' => 'true', 'enabled' => true])
            ->assertNotFound();

        $this->actingAs($stranger)
            ->delete(route('cron-jobs.destroy', $job))
            ->assertNotFound();

        $this->assertModelExists($job);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_the_page_shows_where_the_latest_change_stands()
    {
        $account = User::factory()->create();

        $this->actingAs($account)->post(route('cron-jobs.store'), ['schedule' => '@daily', 'command' => 'true']);

        $this->actingAs($account)
            ->get(route('cron-jobs.index'))
            ->assertInertia(fn (Assert $page) => $page->where('sync', ['pending' => true, 'error' => null]));

        Task::query()->sole()->forceFill(['status' => TaskStatus::Failed, 'error' => 'install the crontab failed'])->save();

        $this->actingAs($account)
            ->get(route('cron-jobs.index'))
            ->assertInertia(fn (Assert $page) => $page->where('sync', ['pending' => false, 'error' => 'install the crontab failed']));
    }
}
