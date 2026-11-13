<?php

namespace Tests\Feature\Notifications;

use App\Agent\AgentClient;
use App\Enums\SslStatus;
use App\Enums\TaskStatus;
use App\Events\AgentTaskFinished;
use App\Jobs\DeliverAlert;
use App\Models\Domain;
use App\Models\NotificationChannel;
use App\Models\Package;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use App\Services\DomainLimitService;
use App\Services\NotificationService;
use App\Services\TelegramBot;
use App\Support\NotificationEvents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '123456789:AAEhBOweik6ad6PsVMRxjeQKzjnB1x7hHj0';

    private User $admin;

    private User $reseller;

    private User $account;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['app.url' => 'https://server.example.net:2222']);

        $this->admin = User::factory()->admin()->create();
        $this->reseller = User::factory()->reseller()->create();
        $this->account = User::factory()->create(['username' => 'budi', 'owner_id' => $this->reseller->id]);
    }

    public function test_an_account_chooses_alerts_its_role_may_get()
    {
        $this->actingAs($this->account)
            ->get(route('notifications.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/notifications')
                ->where('events', ['quota_exceeded', 'ssl_failed', 'ticket'])
                ->where('channels.email', null)
                ->where('telegram_bot', null));

        $this->actingAs($this->account)
            ->put(route('notifications.update', 'email'), ['target' => 'budi@example.com', 'events' => ['service_failed'], 'enabled' => true])
            ->assertSessionHasErrors('events.0');

        $this->actingAs($this->account)
            ->put(route('notifications.update', 'email'), ['target' => 'budi@example.com', 'events' => ['ssl_failed'], 'enabled' => true])
            ->assertSessionHasNoErrors();

        $channel = NotificationChannel::query()->sole();
        $this->assertSame(['email', 'budi@example.com', ['ssl_failed']], [$channel->type, $channel->target, $channel->events]);

        // Telegram needs the server's bot first, and a chat id.
        $this->actingAs($this->account)
            ->put(route('notifications.update', 'telegram'), ['target' => '123456789', 'events' => [], 'enabled' => true])
            ->assertSessionHasErrors('target');

        $this->actingAs($this->account)
            ->put(route('notifications.update', 'telegram'), ['target' => '@budi', 'events' => [], 'enabled' => true])
            ->assertSessionHasErrors(['target' => 'A Telegram chat id is a number, like 123456789.']);
    }

    public function test_the_administrator_sets_up_the_bot_and_its_token_stays_secret()
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['username' => 'rayxo_alert_bot']])]);

        $this->actingAs($this->admin)
            ->put(route('admin.server-settings.telegram'), ['token' => self::TOKEN])
            ->assertSessionHasNoErrors();

        $this->assertSame('rayxo_alert_bot', app(TelegramBot::class)->username());
        $this->assertStringNotContainsString(self::TOKEN, json_encode(Setting::query()->where('key', TelegramBot::SETTING)->value('value')));
        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/getMe'));

        $this->actingAs($this->admin)
            ->put(route('admin.server-settings.telegram'), ['token' => 'not-a-token'])
            ->assertSessionHasErrors('token');

        $this->actingAs($this->account)
            ->put(route('admin.server-settings.telegram'), ['token' => self::TOKEN])
            ->assertForbidden();

        $this->actingAs($this->admin)->delete(route('admin.server-settings.telegram.destroy'))->assertSessionHasNoErrors();
        $this->assertNull(app(TelegramBot::class)->username());
    }

    public function test_telegram_errors_never_carry_the_token()
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401)]);

        $this->actingAs($this->admin)
            ->put(route('admin.server-settings.telegram'), ['token' => self::TOKEN])
            ->assertSessionHasErrors(['token' => 'Telegram did not accept the token: Unauthorized']);

        Http::fake(fn () => throw new ConnectionException('cURL error 28 for https://api.telegram.org/bot'.self::TOKEN.'/getMe'));

        $response = $this->actingAs($this->admin)->put(route('admin.server-settings.telegram'), ['token' => self::TOKEN]);

        $response->assertSessionHasErrors(['token' => 'Telegram did not accept the token: Telegram could not be reached.']);
        $this->assertStringNotContainsString(self::TOKEN, json_encode(session('errors')->all()));
    }

    public function test_an_alert_about_an_account_reaches_it_its_reseller_and_the_administrators_once()
    {
        $everything = array_keys(NotificationEvents::ALL);

        foreach ([$this->account, $this->reseller, $this->admin] as $user) {
            NotificationChannel::factory()->for($user)->create(['events' => $everything]);
        }

        // Someone else's, one that is off and one for other alerts get nothing.
        NotificationChannel::factory()->create(['events' => $everything]);
        NotificationChannel::factory()->for($this->admin)->telegram()->create(['events' => ['ssl_failed'], 'enabled' => false]);
        NotificationChannel::factory()->for(User::factory()->admin())->create(['events' => ['service_failed']]);

        $notifications = app(NotificationService::class);

        $this->assertSame(3, $notifications->aboutAccount($this->account, NotificationEvents::QUOTA_EXCEEDED, 'Full', 'Body', 'test:1'));
        $this->assertSame(0, $notifications->aboutAccount($this->account, NotificationEvents::QUOTA_EXCEEDED, 'Full', 'Body', 'test:1'));

        Queue::assertPushed(DeliverAlert::class, 3);
    }

    public function test_alerts_are_sent_by_email_and_telegram_and_failures_are_kept()
    {
        config(['mail.default' => 'array']);
        Setting::query()->create(['key' => TelegramBot::SETTING, 'value' => ['token' => encrypt(self::TOKEN, false), 'username' => 'rayxo_alert_bot']]);
        Http::fake(['api.telegram.org/*' => Http::sequence()
            ->push(['ok' => true, 'result' => []])
            ->push(['ok' => false, 'description' => 'Forbidden: bot was blocked by the user'], 403)]);

        $email = NotificationChannel::factory()->for($this->account)->create(['target' => 'budi@example.com']);
        $telegram = NotificationChannel::factory()->for($this->account)->telegram('987654321')->create();

        (new DeliverAlert($email->id, 'Disk full', 'Free some space.'))->handle(app(TelegramBot::class));
        (new DeliverAlert($telegram->id, 'Disk full', 'Free some space.'))->handle(app(TelegramBot::class));

        $sent = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        $this->assertSame('[RAYXO server.example.net] Disk full', $sent[0]->getOriginalMessage()->getSubject());
        $this->assertNotNull($email->fresh()->last_sent_at);

        Http::assertSent(fn (HttpRequest $request) => $request['chat_id'] === '987654321'
            && str_contains($request['text'], 'Free some space.'));
        $this->assertNotNull($telegram->fresh()->last_sent_at);

        // The next one fails, and the channel says why.
        (new DeliverAlert($telegram->id, 'Disk full', 'Again.'))->handle(app(TelegramBot::class));
        $this->assertSame('Forbidden: bot was blocked by the user', $telegram->fresh()->last_error);

        $this->actingAs($this->account)
            ->get(route('notifications.index'))
            ->assertInertia(fn (Assert $page) => $page->where('channels.telegram.last_error', 'Forbidden: bot was blocked by the user'));
    }

    public function test_a_test_alert_goes_out_at_once()
    {
        // Sent at once, through the sync connection, which the fake would
        // otherwise only record.
        Queue::fakeExcept([DeliverAlert::class]);
        config(['mail.default' => 'array']);
        NotificationChannel::factory()->for($this->account)->create(['target' => 'budi@example.com']);

        $this->actingAs($this->account)->post(route('notifications.test', 'email'))->assertSessionHasNoErrors();

        // The mail itself is checked above.
        $this->assertNotNull(NotificationChannel::query()->sole()->last_sent_at);
        $this->actingAs($this->account)->post(route('notifications.test', 'telegram'))->assertNotFound();
    }

    public function test_a_website_over_its_limit_and_a_failed_certificate_send_alerts()
    {
        NotificationChannel::factory()->for($this->account)->create(['events' => ['quota_exceeded', 'ssl_failed']]);
        $website = Domain::factory()->for($this->account, 'account')->create(['name' => 'example.com', 'disk_limit_mb' => 100, 'disk_used_mb' => 150]);

        app(DomainLimitService::class)->check($website, null);

        Queue::assertPushed(DeliverAlert::class, fn (DeliverAlert $job) => $job->subject === 'The website example.com reached its disk limit');

        $website->forceFill(['ssl_status' => SslStatus::Pending])->save();
        $task = Task::query()->create(['user_id' => $this->account->id, 'action' => 'ssl.issue', 'payload' => ['username' => 'budi', 'domain' => 'example.com'], 'status' => TaskStatus::Failed]);
        $task->forceFill(['error' => 'NXDOMAIN looking up A for example.com'])->save();
        event(new AgentTaskFinished($task));

        Queue::assertPushed(DeliverAlert::class, fn (DeliverAlert $job) => $job->subject === 'No certificate for example.com'
            && str_contains($job->body, 'NXDOMAIN'));
    }

    public function test_the_scheduled_check_alerts_about_services_the_disk_and_full_accounts()
    {
        NotificationChannel::factory()->for($this->admin)->create();
        $events = [['time' => '2026-11-13T01:00:00Z', 'unit' => 'nginx.service', 'label' => 'Nginx', 'outcome' => 'restarted', 'message' => 'was not running; started again']];
        $disk = ['mount' => '/', 'total_gb' => 100, 'used_gb' => 50];

        $this->mock(AgentClient::class, function (MockInterface $mock) use (&$events, &$disk): void {
            $mock->shouldReceive('call')->with('service.status', [], 30)->andReturnUsing(function () use (&$events): array {
                return ['events' => $events];
            });
            $mock->shouldReceive('call')->with('system.info', [], 30)->andReturnUsing(function () use (&$disk): array {
                return ['disk' => $disk];
            });
        });

        // The first check only learns what happened before.
        $this->artisan('rayxo:check-alerts')->assertSuccessful();
        Queue::assertNothingPushed();

        array_unshift($events, ['time' => '2026-11-13T02:00:00Z', 'unit' => 'mariadb.service', 'label' => 'MariaDB', 'outcome' => 'restart_failed', 'message' => 'Job failed']);
        $disk['used_gb'] = 95;
        $package = Package::factory()->create(['disk_mb' => 100]);
        $this->account->forceFill(['package_id' => $package->id])->save();
        $this->account->diskUsage()->create(['disk_kb' => 200 * 1024, 'inodes' => 10, 'measured_at' => now()]);

        $this->artisan('rayxo:check-alerts')->assertSuccessful();

        $subjects = Queue::pushed(DeliverAlert::class)->map(fn (DeliverAlert $job) => $job->subject)->all();
        $this->assertSame([
            'MariaDB stopped and could not be started again',
            'The server disk is 95% full',
            'Account budi used all of its disk space',
        ], $subjects);

        // Nothing new: nothing again.
        $this->artisan('rayxo:check-alerts')->assertSuccessful();
        Queue::assertPushed(DeliverAlert::class, 3);
    }
}
