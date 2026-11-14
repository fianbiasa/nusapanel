<?php

namespace App\Services;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Jobs\TransferAccount;
use App\Models\AccountTransfer;
use App\Models\Package;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Hosting accounts moved here from another RAYXO server, like cPanel's
 * transfer tool. The administrator gives the other panel's address and an
 * administrator's or reseller's login key there (with accounts:read,
 * backups:read and backups:write); for each account chosen, the other
 * server makes a backup through its API, this one downloads it, keeps it
 * with its own backups and makes the account again from it (see
 * AccountRestoreService). The other server and its accounts stay as they
 * are; the domains move once their DNS points here.
 */
class AccountTransferService
{
    /**
     * The login key's permissions a move needs on the other server.
     */
    public const PERMISSIONS = ['accounts:read', 'backups:read', 'backups:write'];

    /**
     * How long the other server may take to make one backup.
     */
    private const BACKUP_SECONDS = 6 * 3600;

    private const POLL_SECONDS = 10;

    public function __construct(
        private AgentClient $agent,
        private ServerBackupService $backups,
        private AccountRestoreService $restores,
        private AuditLogger $audit,
    ) {}

    /**
     * The other panel's address as the API is reached: https, host and
     * port, nothing else.
     *
     * @throws ValidationException
     */
    public function normalizeUrl(string $url): string
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ! is_string($parts['host'] ?? null) || $parts['host'] === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw ValidationException::withMessages(['source_url' => __('Enter the other panel\'s address starting with https://, such as https://old.example.com:2222.')]);
        }

        return 'https://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /**
     * Sign in to the other panel with the key, and list its hosting
     * accounts with whether their username is taken here.
     *
     * @return array{source: array{username: string, role: string}, accounts: list<array{username: string, domain: string|null, package: string|null, status: string, taken: bool}>}
     *
     * @throws ValidationException
     */
    public function accountsOn(string $url, string $key, bool $verifyTls): array
    {
        $client = $this->client($url, $key, $verifyTls);
        $me = $this->json($client, 'get', '/api/v1/me')['data'] ?? [];

        if (! in_array($me['role'] ?? null, ['admin', 'reseller'], true)) {
            throw ValidationException::withMessages(['api_key' => __('The key must belong to an administrator or reseller of the other panel.')]);
        }

        $missing = array_diff(self::PERMISSIONS, (array) ($me['login_key']['permissions'] ?? []));

        if ($missing !== []) {
            throw ValidationException::withMessages(['api_key' => __('The key needs these permissions on the other panel: :permissions.', ['permissions' => implode(', ', $missing)])]);
        }

        $accounts = [];
        $page = 1;

        do {
            $listing = $this->json($client, 'get', '/api/v1/accounts', ['per_page' => 100, 'page' => $page]);

            foreach ((array) ($listing['data'] ?? []) as $account) {
                if (is_array($account) && is_string($account['username'] ?? null)) {
                    $accounts[] = [
                        'username' => $account['username'],
                        'domain' => is_string($account['primary_domain'] ?? null) ? $account['primary_domain'] : null,
                        'package' => is_string($account['package']['name'] ?? null) ? $account['package']['name'] : null,
                        'status' => (string) ($account['status'] ?? ''),
                        'taken' => false,
                    ];
                }
            }

            $last = (int) ($listing['meta']['last_page'] ?? 1);
        } while (++$page <= min($last, 100));

        $taken = User::query()->whereIn('username', array_column($accounts, 'username'))->pluck('username')->all();

        foreach ($accounts as $index => $account) {
            $accounts[$index]['taken'] = in_array($account['username'], $taken, true);
        }

        return ['source' => ['username' => (string) ($me['username'] ?? ''), 'role' => (string) $me['role']], 'accounts' => $accounts];
    }

    /**
     * Queue the move of the accounts chosen, one job each on the backups
     * queue. Accounts whose username is taken here are refused.
     *
     * @param  list<string>  $usernames
     * @return list<AccountTransfer>
     *
     * @throws ValidationException
     */
    public function start(string $url, string $key, bool $verifyTls, array $usernames, User $actor): array
    {
        $url = $this->normalizeUrl($url);
        $available = collect($this->accountsOn($url, $key, $verifyTls)['accounts'])->keyBy('username');

        $unknown = array_values(array_diff($usernames, $available->keys()->all()));
        $taken = array_values(array_filter($usernames, fn (string $username): bool => $available[$username]['taken'] ?? false));

        if ($unknown !== []) {
            throw ValidationException::withMessages(['usernames' => __('The other panel has no account :usernames.', ['usernames' => implode(', ', $unknown)])]);
        }

        if ($taken !== []) {
            throw ValidationException::withMessages(['usernames' => __('These usernames are taken here: :usernames.', ['usernames' => implode(', ', $taken)])]);
        }

        if (AccountTransfer::query()->whereIn('username', $usernames)->whereIn('status', [AccountTransfer::STATUS_QUEUED, AccountTransfer::STATUS_BACKING_UP, AccountTransfer::STATUS_DOWNLOADING])->exists()) {
            throw ValidationException::withMessages(['usernames' => __('One of these accounts is already being moved.')]);
        }

        $transfers = [];

        foreach ($usernames as $username) {
            $transfer = new AccountTransfer;
            $transfer->forceFill([
                'actor_id' => $actor->id,
                'source_url' => $url,
                'api_key' => $key,
                'verify_tls' => $verifyTls,
                'username' => $username,
                'status' => AccountTransfer::STATUS_QUEUED,
            ])->save();

            TransferAccount::dispatch($transfer);
            $transfers[] = $transfer;
        }

        $this->audit->record('backup.transfer_started', null, ['source' => $url, 'accounts' => $usernames], $actor);

        return $transfers;
    }

    /**
     * Move one account: the job queued by start().
     */
    public function run(AccountTransfer $transfer): void
    {
        $transfer->refresh();

        if ($transfer->status !== AccountTransfer::STATUS_QUEUED || $transfer->api_key === null) {
            return;
        }

        $actor = User::query()->find($transfer->actor_id) ?? User::query()->where('role', 'admin')->orderBy('id')->first();
        $upload = null;

        try {
            if ($actor === null) {
                throw ValidationException::withMessages(['transfer' => __('No administrator is left to move the account for.')]);
            }

            $client = $this->client($transfer->source_url, $transfer->api_key, $transfer->verify_tls);

            $this->step($transfer, AccountTransfer::STATUS_BACKING_UP);
            $remote = $this->json($client, 'post', '/api/v1/accounts/'.rawurlencode($transfer->username).'/backups')['data'] ?? [];
            $transfer->forceFill(['remote_backup_id' => (int) ($remote['id'] ?? 0)])->save();
            $remote = $this->waitForBackup($client, $transfer);

            $this->step($transfer, AccountTransfer::STATUS_DOWNLOADING);
            $id = Str::random(48);
            $upload = FileManagerService::transferPath($id.'.upload');
            $this->download($client, $transfer, $upload, (int) ($remote['size'] ?? 0));

            $name = $transfer->username.'-'.now()->format('Y-m-d-His').'-transfer.tar.gz';

            try {
                $imported = $this->agent->call('backup.server_import', ['transfer' => $id, 'name' => $name], 3600);
            } catch (AgentException $exception) {
                throw ValidationException::withMessages(['transfer' => $exception->getMessage()]);
            }

            $upload = null;
            $backup = $this->backups->register($name, (int) ($imported['size'] ?? 0), $imported, $actor);
            $transfer->forceFill(['server_backup_id' => $backup->id])->save();

            if ($backup->username !== $transfer->username) {
                throw ValidationException::withMessages(['transfer' => __('The other panel sent a backup of another account.')]);
            }

            $account = $this->restores->restore($backup, ['package_id' => $this->packageFor($imported, $actor)], $actor);

            $transfer->forceFill(['status' => AccountTransfer::STATUS_RESTORING, 'user_id' => $account->id, 'api_key' => null])->save();
        } catch (ValidationException $exception) {
            $this->fail($transfer, (string) collect($exception->errors())->flatten()->first());
        } catch (ConnectionException|RequestException $exception) {
            $this->fail($transfer, __('The other panel could not be reached: :message', ['message' => Str::limit($exception->getMessage(), 300)]));
        } finally {
            if ($upload !== null) {
                File::delete($upload);
            }
        }
    }

    /**
     * Fail moves whose job stopped without a word (a worker that was
     * killed): none takes this long.
     */
    public function closeStale(): void
    {
        AccountTransfer::query()
            ->whereIn('status', [AccountTransfer::STATUS_QUEUED, AccountTransfer::STATUS_BACKING_UP, AccountTransfer::STATUS_DOWNLOADING])
            ->where('updated_at', '<', now()->subHours(13))
            ->each(fn (AccountTransfer $transfer) => $this->fail($transfer, __('The move did not finish.')));
    }

    /**
     * Record that a move failed; the other panel's key is forgotten.
     */
    public function fail(AccountTransfer $transfer, string $message): void
    {
        $transfer->forceFill(['status' => AccountTransfer::STATUS_FAILED, 'error' => $message, 'api_key' => null])->save();
    }

    private function step(AccountTransfer $transfer, string $status): void
    {
        $transfer->forceFill(['status' => $status])->save();
    }

    /**
     * Wait until the other server finished the backup.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function waitForBackup(PendingRequest $client, AccountTransfer $transfer): array
    {
        $until = now()->addSeconds(self::BACKUP_SECONDS);

        while (now()->lessThan($until)) {
            $remote = $this->json($client, 'get', '/api/v1/backups/'.$transfer->remote_backup_id)['data'] ?? [];

            if (($remote['status'] ?? null) === 'completed') {
                return $remote;
            }

            if (($remote['status'] ?? null) === 'failed') {
                throw ValidationException::withMessages(['transfer' => __('The other panel could not back the account up: :message', ['message' => (string) ($remote['error'] ?? '')])]);
            }

            Sleep::for(self::POLL_SECONDS)->seconds();
        }

        throw ValidationException::withMessages(['transfer' => __('The other panel did not finish the backup in time.')]);
    }

    /**
     * Download the backup to an upload the agent takes from the panel.
     *
     * @throws ValidationException
     */
    private function download(PendingRequest $client, AccountTransfer $transfer, string $path, int $size): void
    {
        File::ensureDirectoryExists(dirname($path));

        $response = $client->timeout(self::BACKUP_SECONDS)
            ->withOptions(['sink' => $path])
            ->get('/api/v1/backups/'.$transfer->remote_backup_id.'/download');

        if (! $response->successful() || ! is_file($path) || ($size > 0 && filesize($path) !== $size)) {
            throw ValidationException::withMessages(['transfer' => __('The backup could not be downloaded from the other panel (HTTP :status).', ['status' => $response->status()])]);
        }
    }

    /**
     * The package here with the name the account's package had there, if
     * the actor hands one out.
     *
     * @param  array<string, mixed>  $meta
     */
    private function packageFor(array $meta, User $actor): ?int
    {
        $settings = json_decode((string) ($meta['settings'] ?? ''), true);
        $name = $this->restores->savedAccount(is_array($settings) ? $settings : [])['package'];

        if ($name === null) {
            return null;
        }

        $id = Package::query()->handedOutBy($actor)->where('name', $name)->value('id');

        return $id === null ? null : (int) $id;
    }

    private function client(string $url, string $key, bool $verifyTls): PendingRequest
    {
        return Http::baseUrl($url)
            ->withToken($key)
            ->acceptJson()
            ->withUserAgent('RAYXO transfer')
            ->withOptions(['verify' => $verifyTls, 'allow_redirects' => false])
            ->connectTimeout(15)
            ->timeout(60);
    }

    /**
     * Call the other panel's API and return its JSON; an error it answers
     * with is explained.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function json(PendingRequest $client, string $method, string $path, array $query = []): array
    {
        try {
            /** @var Response $response */
            $response = $method === 'post' ? $client->post($path) : $client->get($path, $query);
        } catch (ConnectionException $exception) {
            throw ValidationException::withMessages(['source_url' => __('The other panel could not be reached: :message', ['message' => Str::limit($exception->getMessage(), 300)])]);
        }

        if ($response->status() === 401) {
            throw ValidationException::withMessages(['api_key' => __('The other panel refused the login key.')]);
        }

        if (! $response->successful() || ! is_array($response->json())) {
            $message = is_string($response->json('message')) ? $response->json('message') : 'HTTP '.$response->status();

            throw ValidationException::withMessages(['source_url' => __('The other panel answered with an error: :message', ['message' => Str::limit($message, 300)])]);
        }

        return $response->json();
    }
}
