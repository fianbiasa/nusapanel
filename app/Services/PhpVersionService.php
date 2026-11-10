<?php

namespace App\Services;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use Illuminate\Support\Facades\Cache;

/**
 * The PHP versions websites can run on: those installed on the server, as
 * the agent reports them. setup.sh installs them; the answer is cached for
 * a few minutes, and `rayxo:rebuild-web` (run after every upgrade)
 * forgets it, so newly installed versions show up right away.
 */
class PhpVersionService
{
    public const CACHE_KEY = 'hosting.php_versions';

    public function __construct(private AgentClient $agent) {}

    /**
     * The installed versions, oldest first. While the agent cannot be
     * asked, only the default version, which setup.sh always installs.
     *
     * @return list<string>
     */
    public function available(): array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached) && $cached !== []) {
            /** @var list<string> $cached */
            return $cached;
        }

        try {
            $versions = $this->normalize($this->agent->call('php.versions')['versions'] ?? null);
        } catch (AgentException) {
            $versions = [];
        }

        if ($versions === []) {
            return [$this->default()];
        }

        Cache::put(self::CACHE_KEY, $versions, now()->addMinutes(10));

        return $versions;
    }

    /**
     * The version new websites run on.
     */
    public function default(): string
    {
        return (string) config('hosting.default_php_version');
    }

    /**
     * Ask the agent again on the next call.
     */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return list<string>
     */
    private function normalize(mixed $versions): array
    {
        if (! is_array($versions)) {
            return [];
        }

        $valid = array_values(array_unique(array_filter(
            $versions,
            fn (mixed $version): bool => is_string($version) && preg_match('/^\d\.\d$/', $version) === 1,
        )));

        usort($valid, fn (string $a, string $b): int => version_compare($a, $b));

        return $valid;
    }
}
