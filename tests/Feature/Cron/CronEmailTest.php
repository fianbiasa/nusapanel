<?php

namespace Tests\Feature\Cron;

use App\Models\AuditLog;
use App\Models\CronJob;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CronEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_cron_output_is_mailed_to_the_accounts_address()
    {
        $account = User::factory()->create(['username' => 'budi']);
        CronJob::factory()->for($account, 'account')->create(['schedule' => '@daily', 'command' => 'php backup.php']);

        $this->actingAs($account)
            ->from(route('cron-jobs.index'))
            ->put(route('cron-jobs.email.update'), ['cron_email' => ' ops+cron@example.co.id '])
            ->assertRedirect(route('cron-jobs.index'))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Cron job output is mailed to ops+cron@example.co.id.');

        $this->assertSame('ops+cron@example.co.id', $account->fresh()->cron_email);
        $this->assertSame([
            'username' => 'budi',
            'jobs' => [['schedule' => '@daily', 'command' => 'php backup.php']],
            'mail_to' => 'ops+cron@example.co.id',
        ], Task::query()->where('action', 'cron.apply')->sole()->payload);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'cron_job.email_changed', 'actor_id' => $account->id, 'subject_id' => $account->id]);

        $this->actingAs($account)
            ->get(route('cron-jobs.index'))
            ->assertInertia(fn (Assert $page) => $page->where('cron_email', 'ops+cron@example.co.id'));

        // Every later crontab keeps mailing, and the same address again changes nothing.
        $this->actingAs($account)->post(route('cron-jobs.store'), ['schedule' => '@hourly', 'command' => 'php report.php']);
        $this->assertSame('ops+cron@example.co.id', Task::query()->latest('id')->first()->payload['mail_to']);

        $this->actingAs($account)->put(route('cron-jobs.email.update'), ['cron_email' => 'ops+cron@example.co.id']);
        $this->assertDatabaseCount('tasks', 2);
    }

    public function test_an_empty_address_stops_mailing()
    {
        $account = User::factory()->create(['cron_email' => 'budi@example.com']);

        $this->actingAs($account)
            ->put(route('cron-jobs.email.update'), ['cron_email' => '  '])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Cron job output is no longer mailed.');

        $this->assertNull($account->fresh()->cron_email);
        $this->assertArrayNotHasKey('mail_to', Task::query()->sole()->payload);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeAddresses(): array
    {
        return [
            'no domain' => ['budi'],
            'single label host' => ['budi@localhost'],
            'sendmail option' => ['-oQ/tmp@example.com'],
            'quoted' => ['"budi x"@example.com'],
            'two addresses' => ['a@example.com,b@example.com'],
            'new line' => ["budi@example.com\nSHELL=/bin/sh"],
            'percent' => ['budi%x@example.com'],
            'too long' => [str_repeat('a', 250).'@x.com'],
        ];
    }

    #[DataProvider('unsafeAddresses')]
    public function test_only_plain_addresses_reach_the_crontab(string $address)
    {
        $account = User::factory()->create();

        $this->actingAs($account)
            ->put(route('cron-jobs.email.update'), ['cron_email' => $address])
            ->assertSessionHasErrors('cron_email');

        $this->assertNull($account->fresh()->cron_email);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_a_suspended_account_or_an_admin_cannot_change_it()
    {
        $suspended = User::factory()->suspended()->create();

        $this->actingAs($suspended)
            ->put(route('cron-jobs.email.update'), ['cron_email' => 'budi@example.com'])
            ->assertSessionHasErrors();

        $this->assertNull($suspended->fresh()->cron_email);

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('cron-jobs.email.update'), ['cron_email' => 'budi@example.com'])
            ->assertForbidden();

        $this->assertDatabaseCount('tasks', 0);
    }
}
