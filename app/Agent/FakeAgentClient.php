<?php

namespace App\Agent;

use App\Enums\AccountStatus;
use App\Enums\DatabaseStatus;
use App\Enums\Role;
use App\Models\Database;
use App\Models\Domain;
use App\Models\User;
use App\Services\FileManagerService;

/**
 * Simulated agent for developing the panel on macOS (Herd), where the real
 * Linux agent cannot run. Every response carries `simulated: true`.
 */
final class FakeAgentClient implements AgentClient
{
    /**
     * @var list<array{method: string, params: array<string, mixed>}>
     */
    private array $calls = [];

    public function call(string $method, array $params = [], ?int $timeoutSeconds = null): array
    {
        $this->calls[] = ['method' => $method, 'params' => $params];

        return match ($method) {
            'system.info' => $this->systemInfo(),
            'system.settings' => $this->serverSettings(),
            'system.set_timezone' => ['timezone' => $params['timezone'] ?? 'UTC', 'simulated' => true],
            'ssl.issue_hostname' => $this->serverSettings()['certificate'],
            'system.change_hostname' => ['hostname' => $params['hostname'] ?? '', 'simulated' => true],
            'panel.port_trial' => ['port' => $params['port'] ?? 0, 'previous' => 2222, 'expires_at' => now()->addMinutes(5)->startOfSecond()->toIso8601ZuluString(), 'confirmed' => false, 'simulated' => true],
            'panel.port_confirm' => ['confirmed' => true, 'simulated' => true],
            'panel.port_cancel' => ['closed' => true, 'simulated' => true],
            'ssl.issue' => $this->issuedCertificate((string) ($params['domain'] ?? '')),
            'ssl.expiry' => ['expires_at' => [], 'simulated' => true],
            'account.set_quota' => ['applied' => true, 'simulated' => true],
            'quota.report' => $this->quotaReport(),
            'db.report' => $this->databaseReport(),
            'ftp.ensure_dir' => $this->ftpFolder((string) ($params['username'] ?? '')),
            'php.versions' => ['versions' => ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'], 'simulated' => true],
            'service.status' => $this->serviceReport(),
            'mail.usage' => ['mailboxes' => [], 'simulated' => true],
            'usage.bandwidth' => ['web' => [], 'mail' => [], 'ftp' => [], 'cursor' => [], 'complete' => true, 'simulated' => true],
            'usage.resources' => $this->resourceReport(),
            'backup.inspect' => $this->backupMeta((string) ($params['username'] ?? '')),
            'backup.delete' => ['simulated' => true],
            'backup.server_create' => ['name' => $params['name'] ?? '', 'size' => 1_048_576 + crc32((string) ($params['name'] ?? '')) % 200_000_000, 'skipped' => 0, 'simulated' => true],
            'backup.server_inspect' => $this->backupMeta((string) strstr((string) ($params['name'] ?? ''), '-', true)),
            'backup.server_delete' => ['deleted' => $params['names'] ?? [], 'simulated' => true],
            'backup.server_space' => ['total' => 160 * 1024 ** 3, 'free' => 112 * 1024 ** 3, 'reserve' => 8 * 1024 ** 3, 'simulated' => true],
            'mail.queue_list' => $this->mailQueue(),
            'mail.queue_retry' => ['id' => $params['id'] ?? '', 'simulated' => true],
            'mail.queue_delete' => ['done' => $params['ids'] ?? [], 'failed' => [], 'simulated' => true],
            'service.action' => ['unit' => $params['unit'] ?? '', 'action' => $params['action'] ?? '', 'simulated' => true],
            'fs.list' => $this->fileListing((string) ($params['username'] ?? ''), (string) ($params['path'] ?? '')),
            'fs.read' => ['content' => "<?php\n\necho 'Hello from RAYXO';\n", 'size' => 33, 'version' => 'simulated-1'],
            'fs.write' => ['size' => strlen((string) ($params['content'] ?? '')), 'version' => 'simulated-'.now()->getTimestamp()],
            'fs.copy', 'fs.move', 'fs.delete', 'fs.chmod' => ['done' => $params['paths'] ?? [], 'failed' => [], 'simulated' => true],
            'fs.trash_restore', 'fs.trash_delete' => ['done' => $params['ids'] ?? [], 'failed' => [], 'simulated' => true],
            'fs.trash_empty' => ['done' => [], 'failed' => [], 'simulated' => true],
            'fs.trash_list' => ['items' => [], 'simulated' => true],
            'fs.upload' => $this->fileEntry(basename((string) ($params['path'] ?? 'upload')), 'file', 0),
            'fs.download' => $this->simulatedDownload((string) ($params['transfer'] ?? ''), (string) ($params['path'] ?? '')),
            'fs.compress' => $this->fileEntry(basename((string) ($params['destination'] ?? 'archive.zip')), 'file', 48_213),
            'fs.extract' => ['destination' => $params['destination'] ?? '', 'files' => 12, 'folders' => 3, 'links' => 0, 'skipped' => 0, 'refused' => 0, 'simulated' => true],
            'fs.search' => $this->searchHits((string) ($params['path'] ?? ''), (string) ($params['query'] ?? '')),
            'fs.disk_usage' => [
                'usage' => array_map(fn (string $path): array => ['path' => $path, 'size' => 1_048_576 + crc32($path) % 50_000_000, 'files' => 42, 'folders' => 7, 'truncated' => false], (array) ($params['paths'] ?? [])),
                'failed' => [],
                'simulated' => true,
            ],
            default => ['simulated' => true],
        };
    }

    /**
     * A mail queue as the agent reports it: one message held back by the
     * daily send limit and one waiting for a server that does not answer.
     *
     * @return array<string, mixed>
     */
    private function mailQueue(): array
    {
        return [
            'total' => 2,
            'messages' => [
                [
                    'queue' => 'deferred',
                    'id' => '4C2E520ABA',
                    'arrived_at' => now()->subMinutes(42)->getTimestamp(),
                    'size' => 1830,
                    'sender' => 'news@example.com',
                    'recipients' => [['address' => 'someone@example.org', 'reason' => 'connect to mx.example.org[192.0.2.1]:25: Connection timed out']],
                ],
                [
                    'queue' => 'maildrop',
                    'id' => 'D659820AD2',
                    'arrived_at' => now()->subHours(3)->getTimestamp(),
                    'size' => 229,
                    'sender' => 'info@example.com',
                    'recipients' => [['address' => 'other@example.net']],
                ],
            ],
            'simulated' => true,
        ];
    }

    /**
     * The services of a healthy server, one of them stopped, with an event
     * of the watchdog.
     *
     * @return array<string, mixed>
     */
    private function serviceReport(): array
    {
        $services = [
            ['nginx.service', 'Nginx', false, true],
            ['apache2.service', 'Apache', true, true],
            ['php8.4-fpm.service', 'PHP-FPM (panel & phpMyAdmin)', false, true],
            ['mariadb.service', 'MariaDB', false, true],
            ['postfix.service', 'Postfix', true, true],
            ['opendkim.service', 'OpenDKIM', true, true],
            ['rayxo-srs.service', 'SRS (email diteruskan)', true, true],
            ['pure-ftpd-mysql.service', 'Pure-FTPd', true, true],
            ['cron.service', 'Cron', true, true],
            ['rayxo-queue.service', 'Queue worker panel', false, true],
            ['rayxo-backup-queue.service', 'Worker backup', false, true],
            ['rayxod.service', 'Agent rayxod', false, false],
        ];

        return [
            'services' => array_map(fn (array $service): array => [
                'unit' => $service[0],
                'label' => $service[1],
                'can_stop' => $service[2],
                'can_control' => $service[3],
                'active' => $service[0] === 'opendkim.service' ? 'inactive' : 'active',
                'sub' => $service[0] === 'opendkim.service' ? 'dead' : 'running',
                'enabled' => $service[0] !== 'opendkim.service',
                'since' => now()->subDays(3)->getTimestamp(),
                'memory' => 20_000_000 + crc32($service[0]) % 200_000_000,
                'main_pid' => 1000 + crc32($service[0]) % 30_000,
            ], $services),
            'pools' => ['total' => 3, 'running' => 3, 'failed' => []],
            'events' => [[
                'time' => now()->subHours(5)->toRfc3339String(),
                'unit' => 'pure-ftpd-mysql.service',
                'label' => 'Pure-FTPd',
                'outcome' => 'restarted',
                'message' => 'was not running; started again',
            ]],
            'simulated' => true,
        ];
    }

    /**
     * A home like the agent creates one: the account's websites under
     * domains/, each with a public_html holding a few files.
     *
     * @return array<string, mixed>
     */
    private function fileListing(string $username, string $path): array
    {
        $domains = Domain::query()
            ->whereHas('account', fn ($query) => $query->where('username', $username))
            ->orderBy('name')
            ->pluck('name')
            ->all();

        $segments = $path === '' ? [] : explode('/', $path);

        $entries = match (true) {
            $segments === [] => [
                $this->fileEntry('domains', 'directory'),
                $this->fileEntry('tmp', 'directory'),
                $this->fileEntry('.bash_logout', 'file', 220),
            ],
            $segments === ['domains'] => array_map(fn (string $domain): array => $this->fileEntry($domain, 'directory'), $domains),
            count($segments) === 2 && $segments[0] === 'domains' => [
                $this->fileEntry('public_html', 'directory'),
                [...$this->fileEntry('private_html', 'link'), 'target' => 'public_html', 'target_type' => 'directory'],
            ],
            count($segments) === 3 && $segments[2] === 'public_html' => [
                $this->fileEntry('images', 'directory'),
                $this->fileEntry('.htaccess', 'file', 120),
                $this->fileEntry('index.html', 'file', 629),
                $this->fileEntry('index.php', 'file', 33),
                $this->fileEntry('logo.png', 'file', 18_342),
            ],
            default => [],
        };

        return ['path' => $path, 'entries' => $entries, 'truncated' => false, 'simulated' => true];
    }

    /**
     * Names of the sample home that contain the query.
     *
     * @return array<string, mixed>
     */
    private function searchHits(string $path, string $query): array
    {
        $names = ['index.html', 'index.php', '.htaccess', 'logo.png', 'images'];
        $base = $path === '' ? 'domains/example.test/public_html' : $path;
        $hits = [];

        foreach ($names as $name) {
            if (str_contains(strtolower($name), strtolower($query))) {
                $hits[] = ['path' => $base.'/'.$name, 'type' => $name === 'images' ? 'directory' : 'file', 'size' => 629, 'modified' => now()->getTimestamp()];
            }
        }

        return ['path' => $path, 'query' => $query, 'hits' => $hits, 'truncated' => false, 'simulated' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private function fileEntry(string $name, string $type, int $size = 0): array
    {
        return [
            'name' => $name,
            'type' => $type,
            'size' => $size,
            'mode' => $type === 'directory' ? '0755' : '0644',
            'modified' => now()->subDays(crc32($name) % 30)->getTimestamp(),
        ];
    }

    /**
     * Write a stand-in for the file where the panel expects the download.
     *
     * @return array<string, mixed>
     */
    private function simulatedDownload(string $transfer, string $path): array
    {
        $dir = FileManagerService::transferDir();

        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $content = "Simulated content of {$path}\n";
        file_put_contents($dir.'/'.$transfer.'.download', $content);

        return [...$this->fileEntry(basename($path), 'file', strlen($content)), 'simulated' => true];
    }

    /**
     * The calls made so far, oldest first.
     *
     * @return list<array{method: string, params: array<string, mixed>}>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * @return array<string, mixed>
     */
    private function systemInfo(): array
    {
        return [
            'hostname' => 'server.rayxo.test',
            'os' => ['name' => 'Ubuntu', 'version' => '24.04 LTS', 'kernel' => '6.8.0-generic'],
            'cpu' => ['model' => 'Simulated vCPU', 'cores' => 4],
            'load' => [0.42, 0.37, 0.31],
            'memory' => ['total_mb' => 8192, 'used_mb' => 3172],
            'disk' => ['mount' => '/', 'total_gb' => 160, 'used_gb' => 48],
            'uptime_seconds' => 1_036_800,
            'agent_version' => 'fake',
            'software' => [
                ['name' => 'Nginx', 'version' => '1.24.0'],
                ['name' => 'Apache', 'version' => '2.4.58'],
                ['name' => 'MariaDB', 'version' => '10.11.13'],
                ['name' => 'Postfix', 'version' => '3.8.6'],
                ['name' => 'Dovecot', 'version' => '2.3.21'],
                ['name' => 'PHP 8.4', 'version' => '8.4.14'],
            ],
            'simulated' => true,
        ];
    }

    /**
     * The ids and home of the account an FTP folder was prepared for.
     *
     * @return array<string, mixed>
     */
    private function ftpFolder(string $username): array
    {
        $uid = User::query()->where('username', $username)->value('uid') ?? 2000;

        return ['uid' => $uid, 'gid' => $uid, 'home' => '/home/'.$username, 'simulated' => true];
    }

    /**
     * The size of every database the panel knows, derived from its name so
     * the numbers do not jump around between reports.
     *
     * @return array<string, mixed>
     */
    private function databaseReport(): array
    {
        $databases = Database::query()
            ->where('status', '!=', DatabaseStatus::Pending)
            ->orderBy('name')
            ->pluck('name')
            ->map(fn (string $name): array => ['name' => $name, 'size_bytes' => 16_384 + crc32($name) % 50_000_000])
            ->all();

        return ['databases' => $databases, 'simulated' => true];
    }

    /**
     * The manifest and settings of a backup of the account, as if it had
     * just been made with everything in it.
     *
     * @return array<string, mixed>
     */
    private function backupMeta(string $username): array
    {
        $databases = Database::query()
            ->whereHas('account', fn ($query) => $query->where('username', $username))
            ->orderBy('name')
            ->pluck('name')
            ->all();

        return [
            'manifest' => (string) json_encode(['format' => 1, 'username' => $username, 'created_at' => now()->toIso8601String(), 'files' => true, 'email' => true, 'databases' => $databases]),
            'settings' => (string) json_encode(['format' => 1, 'mailboxes' => [], 'database_users' => []]),
            'simulated' => true,
        ];
    }

    /**
     * The CPU time and memory of every active or suspended account: each
     * uses its own steady share of a CPU (0.2 to 2 percent) in one cgroup,
     * and holds from 32 MB to a few hundred, a little more as the day goes on.
     *
     * @return array<string, mixed>
     */
    private function resourceReport(): array
    {
        $accounts = User::query()
            ->where('role', Role::User)
            ->whereIn('status', [AccountStatus::Active, AccountStatus::Suspended])
            ->orderBy('id')
            ->pluck('username')
            ->map(function (string $username): array {
                $seed = crc32($username);

                return [
                    'username' => $username,
                    'memory_bytes' => (32 + $seed % 200 + now()->hour) * 1_048_576,
                    'cpu' => [['id' => $seed, 'usec' => now()->getTimestamp() * (2_000 + $seed % 18_000)]],
                ];
            });

        return ['accounts' => $accounts->values()->all(), 'simulated' => true];
    }

    /**
     * Disk usage of every hosting account on the server: a steady share of
     * its package's limits, derived from its username so the numbers do
     * not jump around between reports.
     *
     * @return array<string, mixed>
     */
    private function quotaReport(): array
    {
        $accounts = User::query()
            ->with('package')
            ->where('role', Role::User)
            ->whereIn('status', [AccountStatus::Active, AccountStatus::Suspended])
            ->orderBy('id')
            ->get()
            ->map(function (User $account): array {
                $diskLimitKb = ($account->package->disk_mb ?? 0) * 1024;
                $inodeLimit = $account->package->inodes ?? 0;
                $seed = crc32($account->username);
                $diskKb = $diskLimitKb > 0
                    ? intdiv($diskLimitKb * (5 + $seed % 90), 100)
                    : 20_480 + $seed % 2_000_000;
                $inodes = 150 + intdiv($diskKb, 64);

                return [
                    'username' => $account->username,
                    'uid' => $account->uid ?? 1000 + $account->id,
                    'disk_kb' => $diskKb,
                    'inodes' => $inodeLimit > 0 ? min($inodes, $inodeLimit) : $inodes,
                    'disk_limit_kb' => $diskLimitKb,
                    'inode_limit' => $inodeLimit,
                ];
            });

        return [
            'enabled' => true,
            'filesystem' => '/',
            'accounts' => $accounts->values()->all(),
            'simulated' => true,
        ];
    }

    /**
     * A server on Jakarta time whose panel serves its hostname's Let's
     * Encrypt certificate.
     *
     * @return array<string, mixed>
     */
    private function serverSettings(): array
    {
        return [
            'hostname' => 'server1.example.test',
            'timezone' => 'Asia/Jakarta',
            'certificate' => [
                'hostname' => 'server1.example.test',
                'names' => ['server1.example.test'],
                'issuer' => "Let's Encrypt",
                'expires_at' => now()->addDays(64)->startOfSecond()->toIso8601ZuluString(),
                'lets_encrypt' => true,
            ],
            'reconfigure' => null,
            'panel_port' => 2222,
            'port_trial' => null,
            'simulated' => true,
        ];
    }

    /**
     * A Let's Encrypt certificate for the domain and its www name, valid
     * for 90 days.
     *
     * @return array<string, mixed>
     */
    private function issuedCertificate(string $domain): array
    {
        return [
            'names' => [$domain, 'www.'.$domain],
            'expires_at' => now()->addDays(90)->toRfc3339String(),
            'simulated' => true,
        ];
    }
}
