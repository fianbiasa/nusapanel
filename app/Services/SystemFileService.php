<?php

namespace App\Services;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * The server's configuration files administrators edit: RAYXO's own
 * additions to Nginx, Apache, PHP and MariaDB, and the PHP-FPM pool
 * template. The agent keeps the list, tests every change with the program
 * that reads the file, rolls back what it rejects, and keeps backups.
 */
class SystemFileService
{
    /**
     * How long the agent may take: a change to MariaDB restarts it.
     */
    private const TIMEOUT = 120;

    public function __construct(
        private AgentClient $agent,
        private AuditLogger $audit,
        private DomainService $domains,
    ) {}

    /**
     * The editable files, as {id, path}.
     *
     * @return list<array{id: string, path: string}>
     *
     * @throws AgentException
     */
    public function files(): array
    {
        $files = [];

        foreach ($this->agent->call('system.files', [], self::TIMEOUT)['files'] ?? [] as $file) {
            if (is_array($file) && is_string($file['id'] ?? null) && is_string($file['path'] ?? null)) {
                $files[] = ['id' => $file['id'], 'path' => $file['path']];
            }
        }

        return $files;
    }

    /**
     * A file's content, the hash a change must name, and its backups.
     *
     * @return array{content: string, hash: string, backups: list<array{id: string, size: int, created_at: string}>}
     *
     * @throws AgentException
     */
    public function read(string $id): array
    {
        $result = $this->agent->call('system.file_read', ['file' => $id], self::TIMEOUT);
        $backups = [];

        foreach (is_array($result['backups'] ?? null) ? $result['backups'] : [] as $backup) {
            if (is_array($backup) && is_string($backup['id'] ?? null)) {
                $backups[] = ['id' => $backup['id'], 'size' => (int) ($backup['size'] ?? 0), 'created_at' => (string) ($backup['created_at'] ?? '')];
            }
        }

        return [
            'content' => (string) ($result['content'] ?? ''),
            'hash' => (string) ($result['hash'] ?? ''),
            'backups' => $backups,
        ];
    }

    /**
     * Replace a file. The pool template applies to every website, so they
     * are all configured again.
     *
     * @throws ValidationException When the agent refused the change, with its reason.
     */
    public function write(string $id, string $content, string $hash, User $actor): void
    {
        $this->change('system.file_write', ['file' => $id, 'content' => $content, 'hash' => $hash], $id, $actor);
    }

    /**
     * Put a backup back in place of a file.
     *
     * @throws ValidationException When the agent refused the change, with its reason.
     */
    public function restore(string $id, string $backup, string $hash, User $actor): void
    {
        $this->change('system.file_restore', ['file' => $id, 'backup' => $backup, 'hash' => $hash], $id, $actor);
    }

    /**
     * @param  array<string, string>  $params
     *
     * @throws ValidationException
     */
    private function change(string $method, array $params, string $id, User $actor): void
    {
        try {
            $result = $this->agent->call($method, $params, self::TIMEOUT);
        } catch (AgentException $exception) {
            if ($exception->reason === 'changed') {
                throw ValidationException::withMessages(['content' => __('The file changed since you opened it. Open it again to see the change.')]);
            }

            if ($exception->reason === 'invalid') {
                throw ValidationException::withMessages(['content' => __('The change was not saved: :reason', ['reason' => $exception->getMessage()])]);
            }

            report($exception);

            throw ValidationException::withMessages(['content' => __('The file could not be saved. Try again in a moment.')]);
        }

        $this->audit->record($method === 'system.file_write' ? 'system.file_changed' : 'system.file_restored', null, [
            'file' => $id,
            'hash' => $result['hash'] ?? null,
            ...($method === 'system.file_restore' ? ['backup' => $params['backup']] : []),
        ], $actor);

        if ($id === 'php-fpm-pool') {
            $this->domains->reapplyAll($actor);
        }
    }
}
