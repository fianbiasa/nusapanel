<?php

namespace App\Services;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Enums\DomainStatus;
use App\Enums\SslSource;
use App\Enums\SslStatus;
use App\Models\Domain;
use App\Support\NotificationEvents;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the end dates of Let's Encrypt certificates the Domains page shows
 * in step with the server. certbot renews a certificate on its own about a
 * month before it ends, and the panel only learns an end date when it asks
 * for a certificate; without this, a renewed certificate would keep its
 * first end date and be shown as expired after it.
 */
class CertificateExpiryService
{
    /**
     * How many domains one agent call reads, the agent's own limit.
     */
    private const BATCH_SIZE = 500;

    /**
     * Certificates that end within these days get an alert: Let's Encrypt
     * renews them a month before, so one this close is not being renewed.
     */
    public const WARN_DAYS = 7;

    public function __construct(
        private AgentClient $agent,
        private NotificationService $notifications,
    ) {}

    /**
     * Record the end date of every active Let's Encrypt certificate as the
     * server has it now. Subdomains served by their domain's wildcard
     * certificate get the same date.
     *
     * @return int How many domains got a new end date.
     *
     * @throws AgentException
     */
    public function sync(): int
    {
        $updated = 0;

        Domain::query()
            ->where('ssl_status', SslStatus::Active)
            ->where('ssl_source', SslSource::LetsEncrypt)
            ->where('status', '!=', DomainStatus::Deleting)
            ->chunkById(self::BATCH_SIZE, function (Collection $domains) use (&$updated): void {
                $report = $this->agent->call('ssl.expiry', [
                    'domains' => $domains->pluck('name')->all(),
                ], (int) config('agent.timeout'));

                $endDates = is_array($report['expires_at'] ?? null) ? $report['expires_at'] : [];

                /** @var Domain $domain */
                foreach ($domains as $domain) {
                    $expiresAt = $this->parse($endDates[$domain->name] ?? null);

                    if ($expiresAt === null || $domain->ssl_expires_at?->equalTo($expiresAt)) {
                        continue;
                    }

                    DB::transaction(function () use ($domain, $expiresAt): void {
                        $domain->forceFill(['ssl_expires_at' => $expiresAt])->save();

                        $domain->children()
                            ->where('ssl_source', SslSource::Parent)
                            ->update(['ssl_expires_at' => $expiresAt]);
                    });

                    $updated++;
                }
            });

        $this->warnExpiring();

        return $updated;
    }

    /**
     * Alert about active certificates that end within WARN_DAYS: a Let's
     * Encrypt one that is not being renewed, or one the customer installed.
     */
    public function warnExpiring(): int
    {
        $warned = 0;

        Domain::query()
            ->with('account')
            ->where('ssl_status', SslStatus::Active)
            ->whereIn('ssl_source', [SslSource::LetsEncrypt, SslSource::Custom])
            ->where('status', '!=', DomainStatus::Deleting)
            ->whereNotNull('ssl_expires_at')
            ->where('ssl_expires_at', '<', now()->addDays(self::WARN_DAYS))
            ->each(function (Domain $domain) use (&$warned): void {
                $warned += $this->notifications->aboutAccount(
                    $domain->account,
                    NotificationEvents::SSL_FAILED,
                    __('The certificate of :domain ends :date', ['domain' => $domain->display_name, 'date' => $domain->ssl_expires_at?->toDateString()]),
                    $domain->ssl_source === SslSource::LetsEncrypt
                        ? __('It should have been renewed already. Check that :domain still points to this server, then request the certificate again on the Domains page.', ['domain' => $domain->display_name])
                        : __('It is a certificate installed by hand: install a new one on the Domains page before it ends.'),
                    "ssl-expiry:{$domain->id}:".$domain->ssl_expires_at?->toDateString(),
                    72,
                );
            });

        return $warned;
    }

    /**
     * The agent's RFC 3339 end date in the panel's timezone, as the panel
     * stores the date of a certificate it asked for, or null when it sent
     * none.
     */
    private function parse(mixed $expiresAt): ?CarbonImmutable
    {
        if (! is_string($expiresAt)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($expiresAt)->setTimezone((string) config('app.timezone'));
        } catch (InvalidFormatException) {
            return null;
        }
    }
}
