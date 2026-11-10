<?php

namespace App\Services;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Enums\AccountStatus;
use App\Enums\DatabaseStatus;
use App\Enums\Role;
use App\Models\DatabaseUser;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Signs hosting accounts in to phpMyAdmin without a password.
 *
 * Every account has a MariaDB owner user named like the account. Opening
 * phpMyAdmin gives it a new random password and access to the account's
 * databases (through the agent), and seals that password in a ticket only
 * phpMyAdmin's sign-on script can open (installer/files/phpmyadmin-sso.php).
 * The ticket expires after a minute and only works in the browser that got
 * the matching binding cookie. Once the session's time is up, the scheduler
 * gives the owner user a password nobody knows
 * ({@see self::closeExpiredSessions()}). The owner user itself stays, so the
 * views, triggers and routines made in phpMyAdmin keep a valid definer.
 */
class PhpMyAdminService
{
    /**
     * The cookie, for phpMyAdmin's folder, that binds a ticket to the browser
     * that asked for it.
     */
    public const BINDING_COOKIE = 'rayxo_pma_binding';

    /**
     * Seconds a ticket can be used.
     */
    private const TICKET_SECONDS = 60;

    public function __construct(
        private AgentClient $agent,
        private AuditLogger $audit,
    ) {}

    /**
     * Whether phpMyAdmin is set up on this server: the installer configured
     * its URL and the ticket key.
     */
    public static function isAvailable(): bool
    {
        return self::url() !== null && self::key() !== null;
    }

    /**
     * Minutes a phpMyAdmin session lasts.
     */
    public static function sessionMinutes(): int
    {
        return max(5, (int) config('hosting.phpmyadmin.session_minutes'));
    }

    /**
     * Open a phpMyAdmin session for the account: its owner user gets a new
     * password and access to the account's databases. Returns where to post
     * the ticket, the ticket, and the value of the binding cookie.
     *
     * @return array{url: string, ticket: string, binding: string}
     *
     * @throws ValidationException
     */
    public function open(User $account, User $actor): array
    {
        $url = self::url();
        $key = self::key();

        if ($url === null || $key === null) {
            throw ValidationException::withMessages([
                'phpmyadmin' => __('phpMyAdmin is not set up on this server.'),
            ]);
        }

        if (! $account->hasRole(Role::User) || $account->status !== AccountStatus::Active) {
            throw ValidationException::withMessages([
                'phpmyadmin' => __('phpMyAdmin can only be opened while the account is active.'),
            ]);
        }

        /** @var list<string> $databases */
        $databases = $account->databases()
            ->where('status', DatabaseStatus::Active)
            ->orderBy('name')
            ->pluck('name')
            ->all();

        if ($databases === []) {
            throw ValidationException::withMessages([
                'phpmyadmin' => __('Create a database first.'),
            ]);
        }

        $password = Str::password(32, symbols: false);

        try {
            // Opening and closing one account's session never interleave, so
            // a session that just ended cannot take a new password with it.
            Cache::lock($this->lockKey($account), 30)->block(15, function () use ($account, $password, $databases): void {
                $this->agent->call('db.open_sso', [
                    'username' => $account->username,
                    'password_hash' => DatabaseUser::hashPassword($password),
                    'databases' => $databases,
                ], (int) config('agent.timeout'));

                $account->forceFill(['phpmyadmin_expires_at' => now()->addMinutes(self::sessionMinutes())])->save();
            });
        } catch (AgentException|LockTimeoutException $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'phpmyadmin' => __('phpMyAdmin could not be opened. Try again in a moment.'),
            ]);
        }

        $this->audit->record('database.phpmyadmin_opened', $account, [
            'username' => $account->username,
            'databases' => $databases,
        ], $actor);

        $binding = bin2hex(random_bytes(32));

        return [
            'url' => $url.'rayxo-sso.php',
            'ticket' => $this->seal($key, [
                'user' => $account->username,
                'password' => $password,
                'binding' => $binding,
                'expires' => now()->addSeconds(self::TICKET_SECONDS)->getTimestamp(),
            ]),
            'binding' => $binding,
        ];
    }

    /**
     * End every phpMyAdmin session whose time is up: the account's owner user
     * gets a password nobody knows. A session the agent could not end is
     * tried again on the next run.
     *
     * @return int The number of sessions ended.
     */
    public function closeExpiredSessions(): int
    {
        $closed = 0;

        $accounts = User::query()
            ->whereNotNull('phpmyadmin_expires_at')
            ->where('phpmyadmin_expires_at', '<=', now())
            ->get();

        foreach ($accounts as $account) {
            try {
                Cache::lock($this->lockKey($account), 30)->block(15, function () use ($account, &$closed): void {
                    // Opened again meanwhile: that session is not over yet.
                    if ($account->fresh()?->phpmyadmin_expires_at?->isFuture() ?? true) {
                        return;
                    }

                    $this->agent->call('db.close_sso', ['username' => $account->username], (int) config('agent.timeout'));

                    $account->forceFill(['phpmyadmin_expires_at' => null])->save();
                    $closed++;
                });
            } catch (AgentException|LockTimeoutException $exception) {
                report($exception);
            }
        }

        return $closed;
    }

    /**
     * Seal $payload with $key (XSalsa20-Poly1305) as URL-safe base64 of the
     * nonce followed by the box, as the sign-on script expects it.
     *
     * @param  array<string, mixed>  $payload
     */
    private function seal(string $key, array $payload): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = sodium_crypto_secretbox(json_encode($payload, JSON_THROW_ON_ERROR), $nonce, $key);

        return sodium_bin2base64($nonce.$box, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    private function lockKey(User $account): string
    {
        return 'phpmyadmin-session:'.$account->getKey();
    }

    /**
     * phpMyAdmin's HTTPS address, ending in a slash, or null.
     */
    private static function url(): ?string
    {
        $url = config('hosting.phpmyadmin.url');

        if (! is_string($url) || ! str_starts_with($url, 'https://')) {
            return null;
        }

        return rtrim($url, '/').'/';
    }

    /**
     * The 32-byte ticket key, or null.
     */
    private static function key(): ?string
    {
        $key = base64_decode((string) config('hosting.phpmyadmin.sso_key'), true);

        return is_string($key) && strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES ? $key : null;
    }
}
