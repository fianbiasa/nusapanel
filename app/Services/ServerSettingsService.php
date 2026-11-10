<?php

namespace App\Services;

use App\Agent\AgentClient;
use App\Enums\TaskStatus;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use App\Support\PhpSettings;

/**
 * The server-wide settings the admin changes in the panel: the timezone
 * and the hostname's certificate, which live on the server (the agent), and
 * the upload sizes websites may use, which live in the panel's database.
 */
class ServerSettingsService
{
    /**
     * The largest upload and POST size a website may choose.
     */
    public const PHP_UPLOAD_LIMIT = 'php.upload_limit';

    /**
     * The upload and POST size of websites that did not choose their own.
     */
    public const PHP_UPLOAD_DEFAULT = 'php.upload_default';

    public function __construct(
        private AgentClient $agent,
        private AuditLogger $audit,
        private TaskDispatcher $tasks,
    ) {}

    /**
     * The largest upload and POST size websites may choose, such as "1024M".
     */
    public function phpUploadLimit(): string
    {
        $limit = $this->get(self::PHP_UPLOAD_LIMIT);

        return is_string($limit) && in_array($limit, PhpSettings::SIZES, true) ? $limit : PhpSettings::SIZES[array_key_last(PhpSettings::SIZES)];
    }

    /**
     * The upload and POST size of websites that did not choose their own.
     */
    public function phpUploadDefault(): string
    {
        $default = $this->get(self::PHP_UPLOAD_DEFAULT);

        return is_string($default) && in_array($default, PhpSettings::SIZES, true) ? $default : PhpSettings::DEFAULTS['upload_max_filesize'];
    }

    /**
     * The defaults a website's PHP settings start from on this server.
     *
     * @return array<string, string>
     */
    public function phpDefaults(): array
    {
        return PhpSettings::defaults($this->phpUploadDefault());
    }

    /**
     * Store the upload sizes, both from {@see PhpSettings::SIZES}, the
     * default no larger than the limit (checked by the request).
     *
     * @return bool Whether anything changed, so websites must be applied again.
     */
    public function setPhpUploads(string $limit, string $default, User $actor): bool
    {
        $before = ['limit' => $this->phpUploadLimit(), 'default' => $this->phpUploadDefault()];
        $after = ['limit' => $limit, 'default' => $default];

        if ($before === $after) {
            return false;
        }

        $this->put(self::PHP_UPLOAD_LIMIT, $limit);
        $this->put(self::PHP_UPLOAD_DEFAULT, $default);

        $this->audit->record('server.php_uploads_changed', null, ['from' => $before, 'to' => $after], $actor);

        return true;
    }

    /**
     * What the server reports: its hostname, timezone and the certificate
     * the panel serves.
     *
     * @return array<string, mixed>
     */
    public function serverState(): array
    {
        return $this->agent->call('system.settings', [], 20);
    }

    /**
     * Make timezone the server's (cron and logs follow it).
     */
    public function setTimezone(string $timezone, User $actor): void
    {
        $result = $this->agent->call('system.set_timezone', ['timezone' => $timezone], 60);

        $this->audit->record('server.timezone_changed', null, ['timezone' => $result['timezone'] ?? $timezone], $actor);
    }

    /**
     * Queue getting the hostname's Let's Encrypt certificate again for the
     * panel, phpMyAdmin and FTP.
     */
    public function requestHostnameCertificate(User $actor): Task
    {
        $task = $this->tasks->dispatch('ssl.issue_hostname', [], null, $actor);

        $this->audit->record('server.hostname_ssl_requested', null, [], $actor);

        return $task;
    }

    /**
     * Whether the latest hostname certificate request is still running, and
     * why it failed when it did.
     *
     * @return array{pending: bool, error: string|null}
     */
    public function hostnameCertificateRequest(): array
    {
        $task = Task::query()
            ->where('action', 'ssl.issue_hostname')
            ->latest('created_at')
            ->latest('id')
            ->first();

        return [
            'pending' => in_array($task?->status, [TaskStatus::Queued, TaskStatus::Running], true),
            'error' => $task?->status === TaskStatus::Failed ? ($task->error ?? '') : null,
        ];
    }

    private function get(string $key): mixed
    {
        return Setting::query()->where('key', $key)->value('value');
    }

    private function put(string $key, mixed $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
