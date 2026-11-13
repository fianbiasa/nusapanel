<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\DomainStatus;
use App\Enums\DomainType;
use App\Models\Domain;
use App\Models\DomainBandwidth;
use App\Models\User;
use App\Support\NotificationEvents;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The limits an account sets for one of its websites: the bandwidth it may
 * use in a calendar month and the disk its folder may take. A website over
 * a limit shows the page of a suspended account (its alias pointers too)
 * until it is back under it: when the month changes, the limit is raised,
 * or files are deleted and the folder measured again.
 */
class DomainLimitService
{
    public const REACHED_BANDWIDTH = 'bandwidth';

    public const REACHED_DISK = 'disk';

    public function __construct(
        private DomainService $domains,
        private FileManagerService $files,
        private AuditLogger $audit,
        private NotificationService $notifications,
    ) {}

    /**
     * Set the limits of a domain or subdomain (null is no limit) and check
     * it against them right away, with the usage known now. A new disk limit
     * is checked once the folder is measured.
     *
     * @throws ValidationException
     */
    public function setLimits(Domain $website, ?int $bandwidthMb, ?int $diskMb, User $actor): void
    {
        if ($website->type === DomainType::Pointer) {
            throw ValidationException::withMessages([
                'bandwidth_limit_mb' => __('A pointer uses the web settings of its domain.'),
            ]);
        }

        if ($website->status === DomainStatus::Deleting) {
            throw ValidationException::withMessages([
                'bandwidth_limit_mb' => __('This domain is being deleted.'),
            ]);
        }

        if ($website->bandwidth_limit_mb === $bandwidthMb && $website->disk_limit_mb === $diskMb) {
            return;
        }

        DB::transaction(function () use ($website, $bandwidthMb, $diskMb, $actor): void {
            $this->audit->record('domain.limits_changed', $website, [
                'domain' => $website->name,
                'bandwidth_limit_mb' => ['from' => $website->bandwidth_limit_mb, 'to' => $bandwidthMb],
                'disk_limit_mb' => ['from' => $website->disk_limit_mb, 'to' => $diskMb],
                'username' => $website->account->username,
            ], $actor);

            $website->forceFill(['bandwidth_limit_mb' => $bandwidthMb, 'disk_limit_mb' => $diskMb])->save();

            $this->check($website, $actor);
        });
    }

    /**
     * The bytes the website moved in the current calendar month, as the
     * bandwidth count recorded them.
     */
    public function monthBytes(Domain $website): int
    {
        return (int) DomainBandwidth::query()
            ->where('domain_id', $website->id)
            ->where('date', '>=', CarbonImmutable::now()->startOfMonth()->toDateString())
            ->sum('bytes');
    }

    /**
     * Which limit the website is over now, if any: bandwidth first.
     */
    public function reachedLimit(Domain $website): ?string
    {
        if ($website->bandwidth_limit_mb !== null
            && $this->monthBytes($website) > $website->bandwidth_limit_mb * BandwidthService::BYTES_PER_MB) {
            return self::REACHED_BANDWIDTH;
        }

        if ($website->disk_limit_mb !== null && $website->disk_used_mb !== null && $website->disk_used_mb > $website->disk_limit_mb) {
            return self::REACHED_DISK;
        }

        return null;
    }

    /**
     * Stop the website when it is over a limit, or let it run again once it
     * is back under: it is applied again only when that changes.
     */
    public function check(Domain $website, ?User $actor): void
    {
        $reached = $this->reachedLimit($website);

        if ($reached === $website->limit_reached) {
            return;
        }

        DB::transaction(function () use ($website, $reached, $actor): void {
            $previous = $website->limit_reached;

            $website->forceFill(['limit_reached' => $reached, 'limit_reached_at' => $reached === null ? null : now()])->save();

            if ($website->status !== DomainStatus::Deleting) {
                $this->domains->reapplyWebsite($website, $actor);
            }

            $this->audit->record($reached === null ? 'domain.limit_cleared' : 'domain.limit_reached', $website, [
                'domain' => $website->name,
                'limit' => $reached ?? $previous,
                'username' => $website->account->username,
            ], $actor);
        });

        if ($reached !== null) {
            $this->notifications->aboutAccount(
                $website->account,
                NotificationEvents::QUOTA_EXCEEDED,
                $reached === self::REACHED_DISK
                    ? __('The website :domain reached its disk limit', ['domain' => $website->display_name])
                    : __('The website :domain reached its bandwidth limit', ['domain' => $website->display_name]),
                __('The website :domain of :username is stopped and shows a page saying it is unavailable, until it is back under its limit or the limit is raised.', [
                    'domain' => $website->display_name,
                    'username' => $website->account->username,
                ]),
                "domain-limit:{$website->id}:{$reached}",
            );
        }
    }

    /**
     * After bandwidth was counted: check every website with a bandwidth
     * limit, and those a bandwidth limit stopped (a new month frees them).
     *
     * @return int How many websites stopped or started again.
     */
    public function checkBandwidth(): int
    {
        return $this->checkEach(Domain::query()
            ->where(fn ($query) => $query
                ->whereNotNull('bandwidth_limit_mb')
                ->orWhere('limit_reached', self::REACHED_BANDWIDTH)));
    }

    /**
     * Measure the folder of every website with a disk limit, as its
     * account, and check it. Websites of accounts that are not active
     * keep their last measurement.
     *
     * @return int How many websites stopped or started again.
     */
    public function measureDisk(): int
    {
        Domain::query()
            ->with('account')
            ->where('type', '!=', DomainType::Pointer)
            ->whereIn('status', [DomainStatus::Active, DomainStatus::Pending, DomainStatus::Failed])
            ->where(fn ($query) => $query->whereNotNull('disk_limit_mb')->orWhere('limit_reached', self::REACHED_DISK))
            ->whereHas('account', fn ($query) => $query->where('status', AccountStatus::Active))
            ->orderBy('id')
            ->each(function (Domain $website): void {
                $folder = self::folder($website);

                try {
                    $usage = $this->files->diskUsage($website->account, [$folder])['usage'][0] ?? null;
                } catch (ValidationException $exception) {
                    report($exception);

                    return;
                }

                if ($usage === null) {
                    return;
                }

                $website->forceFill([
                    'disk_used_mb' => (int) ceil($usage['size'] / BandwidthService::BYTES_PER_MB),
                    'disk_measured_at' => now(),
                ])->save();
            });

        return $this->checkEach(Domain::query()
            ->where(fn ($query) => $query->whereNotNull('disk_limit_mb')->orWhere('limit_reached', self::REACHED_DISK)));
    }

    /**
     * The folder the disk limit of a website counts, relative to the home:
     * domains/<domain> for a website in its own folder, else its document
     * root.
     */
    public static function folder(Domain $website): string
    {
        return $website->document_root ?? "domains/{$website->name}";
    }

    /**
     * @param  Builder<Domain>  $websites
     */
    private function checkEach(Builder $websites): int
    {
        $changed = 0;

        $websites
            ->with('account')
            ->where('type', '!=', DomainType::Pointer)
            ->where('status', '!=', DomainStatus::Deleting)
            ->orderBy('id')
            ->each(function (Domain $website) use (&$changed): void {
                $before = $website->limit_reached;

                $this->check($website, null);

                if ($website->limit_reached !== $before) {
                    $changed++;
                }
            });

        return $changed;
    }
}
