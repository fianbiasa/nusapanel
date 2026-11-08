<?php

namespace App\Agent;

use App\Enums\AccountStatus;
use App\Enums\Role;
use App\Models\User;

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
            'ssl.issue' => $this->issuedCertificate((string) ($params['domain'] ?? '')),
            'account.set_quota' => ['applied' => true, 'simulated' => true],
            'quota.report' => $this->quotaReport(),
            default => ['simulated' => true],
        };
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
            'hostname' => 'server.nusapanel.test',
            'os' => ['name' => 'Ubuntu', 'version' => '24.04 LTS', 'kernel' => '6.8.0-generic'],
            'cpu' => ['model' => 'Simulated vCPU', 'cores' => 4],
            'load' => [0.42, 0.37, 0.31],
            'memory' => ['total_mb' => 8192, 'used_mb' => 3172],
            'disk' => ['mount' => '/', 'total_gb' => 160, 'used_gb' => 48],
            'uptime_seconds' => 1_036_800,
            'agent_version' => 'fake',
            'simulated' => true,
        ];
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
