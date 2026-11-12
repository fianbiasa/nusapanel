<?php

namespace App\Services;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Enums\DomainStatus;
use App\Enums\SslSource;
use App\Enums\SslStatus;
use App\Models\Domain;
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

    public function __construct(private AgentClient $agent) {}

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

        return $updated;
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
