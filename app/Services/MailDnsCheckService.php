<?php

namespace App\Services;

use App\Enums\MailDomainMode;
use App\Models\MailDomain;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Whether the world sees a mail domain's DNS records as this server needs
 * them: MX to this server, SPF allowing it, the DKIM key it signs with and
 * a DMARC policy; for a domain whose mail another service takes, MX to
 * that service and SPF allowing it too. Asked through DNS over HTTPS
 * (Cloudflare, then Google), as setup.sh does, so no stale local cache
 * answers. Domains whose DNS is elsewhere (such as Cloudflare) need these
 * records added there by hand, which is what this check shows. Results
 * are kept five minutes.
 */
class MailDnsCheckService
{
    public const CACHE_SECONDS = 300;

    /**
     * DNS over HTTPS resolvers, asked in this order.
     *
     * @var list<string>
     */
    private const RESOLVERS = ['https://cloudflare-dns.com/dns-query', 'https://dns.google/resolve'];

    /**
     * DNS record type numbers in DNS over HTTPS answers.
     *
     * @var array<string, int>
     */
    private const TYPES = ['A' => 1, 'MX' => 15, 'TXT' => 16];

    /**
     * Each record's status: ok, missing, wrong (present but not as needed)
     * or unknown (DNS could not be asked), what was found, and what it
     * should be.
     *
     * @return array{checked_at: string, records: list<array{key: string, name: string, type: string, status: string, found: list<string>, expected: string}>}
     */
    public function check(MailDomain $mailDomain, bool $fresh = false): array
    {
        $key = "mail-dns-check:{$mailDomain->id}";

        if ($fresh) {
            Cache::forget($key);
        }

        /** @var array{checked_at: string, records: list<array{key: string, name: string, type: string, status: string, found: list<string>, expected: string}>} */
        return Cache::remember($key, self::CACHE_SECONDS, fn (): array => [
            'checked_at' => now()->toIso8601ZuluString(),
            'records' => [
                $this->checkMx($mailDomain),
                $this->checkSpf($mailDomain),
                $this->checkDkim($mailDomain),
                $this->checkDmarc($mailDomain),
            ],
        ]);
    }

    /**
     * @return array{key: string, name: string, type: string, status: string, found: list<string>, expected: string}
     */
    private function checkMx(MailDomain $mailDomain): array
    {
        if ($mailDomain->mode === MailDomainMode::External) {
            return $this->checkExternalMx($mailDomain);
        }

        $domain = $mailDomain->domain->name;
        $ip = (string) config('hosting.public_ip');
        $result = $this->result('mx', $domain, 'MX', "10 mail.{$domain} (A {$ip})");

        $answers = $this->lookup($domain, 'MX');
        if ($answers === null) {
            return [...$result, 'status' => 'unknown'];
        }

        $hosts = array_map(fn (string $answer): string => rtrim((string) preg_replace('/^\d+\s+/', '', $answer), '.'), $answers);
        $result['found'] = $hosts;

        if ($hosts === []) {
            return [...$result, 'status' => 'missing'];
        }

        foreach ($hosts as $host) {
            if (in_array($ip, $this->lookup($host, 'A') ?? [], true)) {
                return [...$result, 'status' => 'ok'];
            }
        }

        return [...$result, 'status' => 'wrong'];
    }

    /**
     * @return array{key: string, name: string, type: string, status: string, found: list<string>, expected: string}
     */
    private function checkSpf(MailDomain $mailDomain): array
    {
        $domain = $mailDomain->domain->name;
        $ip = (string) config('hosting.public_ip');
        $include = $mailDomain->mode === MailDomainMode::External ? $mailDomain->provider?->spfInclude() : null;
        $result = $this->result('spf', $domain, 'TXT', 'v=spf1 a mx ip4:'.$ip.($include !== null ? " {$include}" : '').' ~all');

        $answers = $this->lookup($domain, 'TXT');
        if ($answers === null) {
            return [...$result, 'status' => 'unknown'];
        }

        $spf = array_values(array_filter($answers, fn (string $answer): bool => stripos($answer, 'v=spf1') === 0));
        $result['found'] = $spf;

        if ($spf === []) {
            return [...$result, 'status' => 'missing'];
        }

        // More than one SPF record makes every SPF check fail.
        if (count($spf) > 1) {
            return [...$result, 'status' => 'wrong'];
        }

        $terms = preg_split('/\s+/', strtolower($spf[0])) ?: [];
        $allows = in_array("ip4:{$ip}", $terms, true) || array_intersect(['a', '+a', 'mx', '+mx'], $terms) !== [];

        if ($include !== null && ! in_array($include, $terms, true)) {
            $allows = false;
        }

        return [...$result, 'status' => $allows ? 'ok' : 'wrong'];
    }

    /**
     * The MX records of a domain whose mail another service takes: those
     * of Google Workspace or Zoho when that is the service, and none that
     * lead back to this server.
     *
     * @return array{key: string, name: string, type: string, status: string, found: list<string>, expected: string}
     */
    private function checkExternalMx(MailDomain $mailDomain): array
    {
        $domain = $mailDomain->domain->name;
        $ip = (string) config('hosting.public_ip');
        $exchangers = $mailDomain->provider?->exchangers() ?? [];
        $expected = $exchangers === []
            ? __('the MX records of your email service, not this server')
            : implode(', ', array_map(fn (array $exchanger): string => "{$exchanger['priority']} {$exchanger['host']}", $exchangers));
        $result = $this->result('mx', $domain, 'MX', $expected);

        $answers = $this->lookup($domain, 'MX');
        if ($answers === null) {
            return [...$result, 'status' => 'unknown'];
        }

        $hosts = array_map(fn (string $answer): string => strtolower(rtrim((string) preg_replace('/^\d+\s+/', '', $answer), '.')), $answers);
        $result['found'] = $hosts;

        if ($hosts === []) {
            return [...$result, 'status' => 'missing'];
        }

        $wanted = array_column($exchangers, 'host');

        if (array_diff($wanted, $hosts) !== []) {
            return [...$result, 'status' => 'wrong'];
        }

        foreach (array_diff($hosts, $wanted) as $host) {
            if (in_array($ip, $this->lookup($host, 'A') ?? [], true)) {
                return [...$result, 'status' => 'wrong'];
            }
        }

        return [...$result, 'status' => 'ok'];
    }

    /**
     * @return array{key: string, name: string, type: string, status: string, found: list<string>, expected: string}
     */
    private function checkDkim(MailDomain $mailDomain): array
    {
        $name = "{$mailDomain->dkim_selector}._domainkey.{$mailDomain->domain->name}";
        $key = (string) $mailDomain->dkim_public_key;
        $result = $this->result('dkim', $name, 'TXT', "v=DKIM1; k=rsa; p={$key}");

        $answers = $this->lookup($name, 'TXT');
        if ($answers === null) {
            return [...$result, 'status' => 'unknown'];
        }

        $result['found'] = $answers;

        if ($answers === []) {
            return [...$result, 'status' => 'missing'];
        }

        foreach ($answers as $answer) {
            $compact = (string) preg_replace('/\s+/', '', $answer);

            if ($key !== '' && preg_match('/(?:^|;)p=([^;]*)/', $compact, $match) === 1 && $match[1] === $key) {
                return [...$result, 'status' => 'ok'];
            }
        }

        return [...$result, 'status' => 'wrong'];
    }

    /**
     * @return array{key: string, name: string, type: string, status: string, found: list<string>, expected: string}
     */
    private function checkDmarc(MailDomain $mailDomain): array
    {
        $name = "_dmarc.{$mailDomain->domain->name}";
        $result = $this->result('dmarc', $name, 'TXT', 'v=DMARC1; p=none');

        $answers = $this->lookup($name, 'TXT');
        if ($answers === null) {
            return [...$result, 'status' => 'unknown'];
        }

        $dmarc = array_values(array_filter($answers, fn (string $answer): bool => stripos($answer, 'v=DMARC1') === 0));
        $result['found'] = $answers;

        return [...$result, 'status' => count($dmarc) === 1 ? 'ok' : ($dmarc === [] ? 'missing' : 'wrong')];
    }

    /**
     * @return array{key: string, name: string, type: string, status: string, found: list<string>, expected: string}
     */
    private function result(string $key, string $name, string $type, string $expected): array
    {
        return ['key' => $key, 'name' => $name, 'type' => $type, 'status' => 'unknown', 'found' => [], 'expected' => $expected];
    }

    /**
     * The records of name and type as public DNS answers them (TXT strings
     * joined), [] when there are none, or null when no resolver answered.
     *
     * @return list<string>|null
     */
    private function lookup(string $name, string $type): ?array
    {
        foreach (self::RESOLVERS as $resolver) {
            try {
                $response = Http::timeout(5)
                    ->withHeaders(['Accept' => 'application/dns-json'])
                    ->get($resolver, ['name' => $name, 'type' => $type]);
            } catch (ConnectionException) {
                continue;
            }

            if (! $response->successful() || ! is_array($response->json())) {
                continue;
            }

            // NXDOMAIN (3) is an answer too: there are no records.
            $status = (int) $response->json('Status', -1);
            if ($status !== 0 && $status !== 3) {
                continue;
            }

            $records = [];

            foreach ((array) $response->json('Answer', []) as $answer) {
                if (is_array($answer) && (int) ($answer['type'] ?? 0) === self::TYPES[$type] && is_string($answer['data'] ?? null)) {
                    $records[] = $type === 'TXT' ? $this->joinTxt($answer['data']) : $answer['data'];
                }
            }

            return $records;
        }

        return null;
    }

    /**
     * A TXT record's text, its quoted strings joined.
     */
    private function joinTxt(string $data): string
    {
        if (preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $data, $matches) > 0) {
            return implode('', array_map(fn (string $part): string => stripcslashes($part), $matches[1]));
        }

        return $data;
    }
}
