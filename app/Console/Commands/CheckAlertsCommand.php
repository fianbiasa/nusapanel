<?php

namespace App\Console\Commands;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Enums\AccountStatus;
use App\Enums\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\NotificationEvents;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Alerts about what the panel only learns by looking: services the agent's
 * watchdog restarted, a full server disk and accounts that filled their
 * disk quota. The scheduler runs it every five minutes.
 */
class CheckAlertsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'rayxo:check-alerts';

    /**
     * @var string
     */
    protected $description = 'Send alerts about restarted services, a full server disk and accounts over their disk quota';

    /**
     * When the newest watchdog event already alerted about happened.
     */
    public const WATCHDOG_SEEN = 'notifications.watchdog_seen_at';

    /**
     * The server's disk is reported full from this share used.
     */
    public const DISK_FULL_PERCENT = 90;

    public function handle(AgentClient $agent, NotificationService $notifications): int
    {
        try {
            $this->checkWatchdog($agent, $notifications);
            $this->checkServerDisk($agent, $notifications);
        } catch (AgentException $exception) {
            report($exception);
            $this->components->error('The server agent did not answer: '.$exception->getMessage());
        }

        $this->checkAccountDisks($notifications);

        return self::SUCCESS;
    }

    /**
     * Alert about watchdog events since the last check. The first check
     * only marks what happened before as seen.
     */
    private function checkWatchdog(AgentClient $agent, NotificationService $notifications): void
    {
        /** @var list<array{time?: string, unit?: string, label?: string, outcome?: string, message?: string}> $events */
        $events = $agent->call('service.status', [], 30)['events'] ?? [];

        $seen = Setting::query()->where('key', self::WATCHDOG_SEEN)->value('value');
        $seenAt = is_string($seen) ? CarbonImmutable::parse($seen) : null;
        $newest = $seenAt;

        foreach ($events as $event) {
            $time = CarbonImmutable::parse((string) ($event['time'] ?? 'now'));
            $newest = $newest === null || $time->greaterThan($newest) ? $time : $newest;

            if ($seenAt === null || ! $time->greaterThan($seenAt)) {
                continue;
            }

            $label = (string) ($event['label'] ?? $event['unit'] ?? '');

            $notifications->toAdmins(
                NotificationEvents::SERVICE_FAILED,
                match ($event['outcome'] ?? '') {
                    'restarted' => __(':service stopped and was started again', ['service' => $label]),
                    'gave_up' => __(':service keeps stopping; the watchdog stopped restarting it', ['service' => $label]),
                    default => __(':service stopped and could not be started again', ['service' => $label]),
                },
                trim(__('At :time.', ['time' => $time->toDateTimeString()]).' '.($event['message'] ?? '')),
                'watchdog:'.$time->format('U.u').':'.($event['unit'] ?? ''),
            );
        }

        if ($newest !== null && ($seenAt === null || $newest->greaterThan($seenAt))) {
            Setting::query()->updateOrCreate(['key' => self::WATCHDOG_SEEN], ['value' => $newest->toIso8601ZuluString('microsecond')]);
        }
    }

    private function checkServerDisk(AgentClient $agent, NotificationService $notifications): void
    {
        $disk = $agent->call('system.info', [], 30)['disk'] ?? [];
        $total = (float) ($disk['total_gb'] ?? 0);
        $used = (float) ($disk['used_gb'] ?? 0);

        if ($total <= 0 || $used / $total * 100 < self::DISK_FULL_PERCENT) {
            return;
        }

        $notifications->toAdmins(
            NotificationEvents::DISK_FULL,
            __('The server disk is :percent% full', ['percent' => (int) floor($used / $total * 100)]),
            __(':used of :total GB is used on :mount. Websites, mail and databases stop working when it is full.', [
                'used' => round($used, 1),
                'total' => round($total, 1),
                'mount' => (string) ($disk['mount'] ?? '/'),
            ]),
            'disk-full',
        );
    }

    /**
     * Accounts whose files fill their package's disk.
     */
    private function checkAccountDisks(NotificationService $notifications): void
    {
        User::query()
            ->with(['package', 'diskUsage', 'owner'])
            ->where('role', Role::User)
            ->where('status', AccountStatus::Active)
            ->whereHas('package', fn ($query) => $query->whereNotNull('disk_mb'))
            ->whereHas('diskUsage')
            ->each(function (User $account) use ($notifications): void {
                $limit = $account->package?->limit('disk_mb');
                $used = $account->diskUsage?->diskMegabytes();

                if ($limit === null || $used === null || $used < $limit) {
                    return;
                }

                $notifications->aboutAccount(
                    $account,
                    NotificationEvents::QUOTA_EXCEEDED,
                    __('Account :username used all of its disk space', ['username' => $account->username]),
                    __('The account :username uses :used of its :limit MB. Uploads, new mail and database writes fail until space is freed or the package allows more.', [
                        'username' => $account->username,
                        'used' => $used,
                        'limit' => $limit,
                    ]),
                    "disk-quota:{$account->id}",
                );
            });
    }
}
