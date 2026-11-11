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

    /**
     * How many recipients each hosting account may send mail to per 24
     * hours; null is no limit.
     */
    public const MAIL_SEND_LIMIT_ACCOUNT = 'mail.send_limit_account';

    /**
     * How many recipients all hosting accounts together may send mail to
     * per 24 hours; null is no limit.
     */
    public const MAIL_SEND_LIMIT_SERVER = 'mail.send_limit_server';

    /**
     * The limit of each account until the admin changes it.
     */
    public const DEFAULT_MAIL_SEND_LIMIT_ACCOUNT = 300;

    /**
     * The largest limit the agent takes.
     */
    public const MAX_MAIL_SEND_LIMIT = 10_000_000;

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
     * The daily send limits, in recipients per 24 hours (null: no limit).
     * Each account has DEFAULT_MAIL_SEND_LIMIT_ACCOUNT until the admin sets
     * another limit or none; the server has none until the admin sets one.
     *
     * @return array{account: int|null, server: int|null}
     */
    public function mailSendLimits(): array
    {
        $account = Setting::query()->where('key', self::MAIL_SEND_LIMIT_ACCOUNT)->first();

        return [
            'account' => $account === null ? self::DEFAULT_MAIL_SEND_LIMIT_ACCOUNT : $this->limitOrNull($account->value),
            'server' => $this->limitOrNull($this->get(self::MAIL_SEND_LIMIT_SERVER)),
        ];
    }

    /**
     * Store the daily send limits (null: no limit).
     *
     * @return bool Whether anything changed, so the server must get them.
     */
    public function setMailSendLimits(?int $account, ?int $server, User $actor): bool
    {
        $before = $this->mailSendLimits();
        $after = ['account' => $account, 'server' => $server];

        if ($before === $after) {
            return false;
        }

        // No limit is stored as 0: a setting's value cannot be empty.
        $this->put(self::MAIL_SEND_LIMIT_ACCOUNT, $account ?? 0);
        $this->put(self::MAIL_SEND_LIMIT_SERVER, $server ?? 0);

        $this->audit->record('server.mail_send_limits_changed', null, ['from' => $before, 'to' => $after], $actor);

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
     * Have the server take a new hostname: the agent runs setup.sh again in
     * the background, which first checks the name's A record and PTR and
     * changes nothing when they are wrong.
     */
    public function changeHostname(string $hostname, User $actor): void
    {
        $this->agent->call('system.change_hostname', ['hostname' => $hostname], 30);

        $this->audit->record('server.hostname_change_started', null, ['hostname' => $hostname], $actor);
    }

    /**
     * Open port for the panel as well, for a few minutes, so the admin can
     * check it is reachable before it becomes the panel's port.
     *
     * @return array<string, mixed> The trial: port, previous port and when it closes again.
     */
    public function tryPanelPort(int $port, User $actor): array
    {
        $trial = $this->agent->call('panel.port_trial', ['port' => $port], 30);

        $this->audit->record('server.panel_port_tried', null, ['port' => $port], $actor);

        return $trial;
    }

    /**
     * Make the port being tried the panel's: setup.sh writes every config
     * for it in the background.
     */
    public function confirmPanelPort(int $port, User $actor): void
    {
        $this->agent->call('panel.port_confirm', [], 30);

        $this->audit->record('server.panel_port_changed', null, ['port' => $port], $actor);
    }

    /**
     * Close the port being tried; the panel keeps its port.
     */
    public function cancelPanelPort(User $actor): void
    {
        $this->agent->call('panel.port_cancel', [], 30);

        $this->audit->record('server.panel_port_cancelled', null, [], $actor);
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

    private function limitOrNull(mixed $value): ?int
    {
        return is_int($value) && $value >= 1 && $value <= self::MAX_MAIL_SEND_LIMIT ? $value : null;
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
