<?php

namespace App\Services;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The logs of a website and its visitor report. The agent reads them for
 * the account whose website it is: the end of a log, every kept log as a
 * download, and a GoAccess report of the last 14 days. Reports are made
 * when they are opened and not kept.
 */
class SiteLogService
{
    /**
     * The logs a website has: Nginx's access and error log, and Apache's
     * error log, where PHP errors end up.
     */
    public const KINDS = ['access', 'error', 'apache-error'];

    /**
     * How many lines the page can show.
     */
    public const LINE_CHOICES = [100, 500, 1000];

    public const MAX_FILTER_LENGTH = 200;

    private const TAIL_TIMEOUT = 30;

    private const LONG_TIMEOUT = 600;

    public function __construct(
        private AgentClient $agent,
        private AuditLogger $audit,
    ) {}

    /**
     * The last lines of a log of the website, oldest first, or why they
     * could not be read.
     *
     * @return array{lines: list<string>, size: int, modified_at: string|null, partial: bool, error: string|null}
     */
    public function tail(Domain $website, string $kind, int $lines, string $filter): array
    {
        try {
            /** @var array{lines?: list<string>, size?: int, modified_at?: string|null, partial?: bool} $tail */
            $tail = $this->agent->call('logs.tail', [
                'username' => $website->account->username,
                'domain' => $website->name,
                'kind' => $kind,
                'lines' => $lines,
                'filter' => $filter,
            ], self::TAIL_TIMEOUT);
        } catch (AgentException $exception) {
            if ($exception->reason !== 'no_website') {
                report($exception);
            }

            return ['lines' => [], 'size' => 0, 'modified_at' => null, 'partial' => false, 'error' => $this->message($exception)];
        }

        return [
            'lines' => $tail['lines'] ?? [],
            'size' => $tail['size'] ?? 0,
            'modified_at' => $tail['modified_at'] ?? null,
            'partial' => $tail['partial'] ?? false,
            'error' => null,
        ];
    }

    /**
     * Every kept log of the kind, gzipped, ready to download.
     *
     * @return array{file: string, name: string}
     *
     * @throws ValidationException
     */
    public function download(Domain $website, string $kind, User $actor): array
    {
        $transfer = $this->fetch('logs.download', $website, ['kind' => $kind]);

        $this->audit->record('domain.log_downloaded', $website, [
            'domain' => $website->name,
            'kind' => $kind,
        ], $actor);

        return [
            'file' => FileManagerService::transferPath($transfer.'.download'),
            'name' => $website->name.'-'.$kind.'-'.now()->format('Ymd').'.log.gz',
        ];
    }

    /**
     * The GoAccess report of the website's visits: an HTML file, made now.
     *
     * @throws ValidationException
     */
    public function statistics(Domain $website): string
    {
        return FileManagerService::transferPath($this->fetch('stats.report', $website).'.download');
    }

    /**
     * Have the agent write a download for the website and return its
     * transfer id.
     *
     * @param  array<string, mixed>  $params
     *
     * @throws ValidationException
     */
    private function fetch(string $method, Domain $website, array $params = []): string
    {
        $transfer = Str::random(48);

        try {
            $this->agent->call($method, [
                'username' => $website->account->username,
                'domain' => $website->name,
                ...$params,
                'transfer' => $transfer,
            ], self::LONG_TIMEOUT);
        } catch (AgentException $exception) {
            if (! in_array($exception->reason, ['no_website', 'no_visits'], true)) {
                report($exception);
            }

            throw ValidationException::withMessages(['log' => $this->message($exception)]);
        }

        return $transfer;
    }

    private function message(AgentException $exception): string
    {
        return match ($exception->reason) {
            'no_website' => __('The logs can be read once the website is set up on the server.'),
            'no_visits' => __('The website has no visits in its logs yet.'),
            default => __('The logs could not be read. Try again in a moment.'),
        };
    }
}
