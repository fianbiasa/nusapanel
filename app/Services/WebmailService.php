<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\MailboxStatus;
use App\Enums\MailDomainStatus;
use App\Enums\Role;
use App\Models\Mailbox;
use App\Models\User;
use App\Models\WebmailSession;
use App\Support\SignOnTicket;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Signs mailboxes in to Roundcube (https://<hostname>/webmail/) from the
 * panel, without their password.
 *
 * Opening webmail makes a webmail session: a random token whose SHA-256
 * Dovecot accepts in place of the mailbox's password, only from this
 * server (where Roundcube runs) and until the session expires (the
 * v_mail_webmail_sessions view). The token travels in a ticket sealed with
 * the key Roundcube's rayxo_sso plugin shares
 * (installer/files/roundcube-rayxo_sso.php); the ticket expires after a
 * minute and only works in the browser that got the matching binding
 * cookie. Roundcube keeps the token in its session and signs in with it on
 * every request, so the session ends when its time is up, its mailbox's
 * password changes, or the mailbox goes.
 */
class WebmailService
{
    /**
     * The cookie, for Roundcube's folder, that binds a ticket to the browser
     * that asked for it.
     */
    public const BINDING_COOKIE = 'rayxo_webmail_binding';

    /**
     * Seconds a ticket can be used.
     */
    private const TICKET_SECONDS = 60;

    /**
     * The most webmail sessions one mailbox keeps; opening another ends the
     * oldest.
     */
    private const MAX_SESSIONS = 10;

    public function __construct(private AuditLogger $audit) {}

    /**
     * Whether webmail is set up on this server: the installer configured
     * its URL and the ticket key.
     */
    public static function isAvailable(): bool
    {
        return self::url() !== null && self::key() !== null;
    }

    /**
     * Roundcube's HTTPS address, ending in a slash, or null.
     */
    public static function url(): ?string
    {
        $url = config('hosting.webmail.url');

        if (! is_string($url) || ! str_starts_with($url, 'https://')) {
            return null;
        }

        return rtrim($url, '/').'/';
    }

    /**
     * Hours a webmail session opened from the panel lasts.
     */
    public static function sessionHours(): int
    {
        return min(168, max(1, (int) config('hosting.webmail.session_hours')));
    }

    /**
     * Open a webmail session for the mailbox. Returns where to post the
     * ticket, the ticket, and the value of the binding cookie.
     *
     * @return array{url: string, ticket: string, binding: string}
     *
     * @throws ValidationException
     */
    public function open(Mailbox $mailbox, User $actor): array
    {
        $url = self::url();
        $key = self::key();

        if ($url === null || $key === null) {
            throw ValidationException::withMessages([
                'webmail' => __('Webmail is not set up on this server.'),
            ]);
        }

        $mailDomain = $mailbox->mailDomain;
        $domain = $mailDomain->domain;
        $account = $domain->account;

        if (! $account->hasRole(Role::User) || $account->status !== AccountStatus::Active) {
            throw ValidationException::withMessages([
                'webmail' => __('Webmail can only be opened while the account is active.'),
            ]);
        }

        if ($mailDomain->status !== MailDomainStatus::Active) {
            throw ValidationException::withMessages([
                'webmail' => __('This email account is not ready yet. Try again in a moment.'),
            ]);
        }

        if ($mailbox->status === MailboxStatus::Suspended) {
            throw ValidationException::withMessages([
                'webmail' => __('This email account is suspended: it cannot sign in until it is unsuspended.'),
            ]);
        }

        $address = $mailbox->local_part.'@'.$domain->name;
        $token = bin2hex(random_bytes(32));

        DB::transaction(function () use ($mailbox, $token): void {
            $session = new WebmailSession;
            $session->forceFill([
                'mailbox_id' => $mailbox->id,
                'token_hash' => WebmailSession::hashToken($token),
                'expires_at' => now()->addHours(self::sessionHours()),
            ])->save();

            $newest = $mailbox->webmailSessions()->orderByDesc('id')->limit(self::MAX_SESSIONS)->pluck('id');
            $mailbox->webmailSessions()->whereNotIn('id', $newest)->delete();
        });

        $this->audit->record('mailbox.webmail_opened', $mailbox, ['address' => $address], $actor);

        $binding = bin2hex(random_bytes(32));

        return [
            'url' => $url.'?_task=login',
            'ticket' => SignOnTicket::seal($key, [
                'user' => $address,
                'password' => $token,
                'binding' => $binding,
                'expires' => now()->addSeconds(self::TICKET_SECONDS)->getTimestamp(),
            ]),
            'binding' => $binding,
        ];
    }

    /**
     * End the webmail sessions the panel opened for the mailbox; Roundcube
     * signs them out on their next request.
     */
    public function endSessions(Mailbox $mailbox): void
    {
        $mailbox->webmailSessions()->delete();
    }

    /**
     * Clear what Roundcube kept of addresses that are gone (their settings,
     * identities, contacts and cache, through its users table), so a later
     * mailbox with the same address starts empty. Does nothing where
     * webmail is not set up; a failure is reported, not thrown.
     *
     * @param  list<string>  $addresses
     */
    public function forget(array $addresses): void
    {
        if ($addresses === [] || ! self::isAvailable()) {
            return;
        }

        try {
            foreach (array_chunk($addresses, 500) as $chunk) {
                DB::connection((string) config('hosting.webmail.connection'))
                    ->table('users')
                    ->whereIn('username', $chunk)
                    ->delete();
            }
        } catch (QueryException $exception) {
            report($exception);
        }
    }

    /**
     * The 32-byte ticket key, or null.
     */
    private static function key(): ?string
    {
        return SignOnTicket::key(config('hosting.webmail.sso_key'));
    }
}
