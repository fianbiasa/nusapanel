<?php

/**
 * Single sign-on from rayxo into Roundcube.
 *
 * The panel opens a webmail session for a mailbox and posts a ticket to
 * /webmail/?_task=login as _rayxo_ticket: the address, the session's token,
 * an expiry a minute away and a binding value, sealed with the key in
 * /etc/rayxo-webmail/sso.key (the panel's WEBMAIL_SSO_KEY). The panel also
 * set the binding value as a cookie for /webmail/ in the same browser, so a
 * ticket only works in the browser that asked for it. This plugin signs in
 * with the address and the token, which Dovecot accepts in place of the
 * mailbox's password from this server only, until the session expires.
 * A ticket that does not open leaves the normal login form.
 *
 * Once Dovecot refuses a signed-in session (its time is up, the mailbox's
 * password changed, the mailbox or its account is gone or suspended), the
 * plugin ends it and sends the browser to the login form, instead of
 * leaving an empty mail view that cannot reach the mailbox.
 *
 * build-release.sh copies this file into the release as
 * roundcube/plugins/rayxo_sso/rayxo_sso.php.
 */
class rayxo_sso extends rcube_plugin
{
    private const KEY_FILE = '/etc/rayxo-webmail/sso.key';

    private const BINDING_COOKIE = 'rayxo_webmail_binding';

    /**
     * The address and token of a ticket that opened, for this request.
     *
     * @var array{user: string, password: string}|null
     */
    private ?array $credentials = null;

    public function init(): void
    {
        $this->add_hook('startup', [$this, 'startup']);
        $this->add_hook('authenticate', [$this, 'authenticate']);
        $this->add_hook('storage_connect', [$this, 'storage_connect']);
    }

    /**
     * Turn a request with a ticket that opens into a login.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function startup(array $args): array
    {
        $ticket = $_POST['_rayxo_ticket'] ?? null;

        if (($args['task'] ?? '') !== 'login' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ! is_string($ticket)) {
            return $args;
        }

        $binding = $_COOKIE[self::BINDING_COOKIE] ?? '';

        // The binding works once.
        setcookie(self::BINDING_COOKIE, '', [
            'expires' => 1,
            'path' => '/webmail/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        $this->credentials = is_string($binding) ? self::open_ticket($ticket, $binding) : null;

        if ($this->credentials !== null) {
            $args['action'] = 'login';
        }

        return $args;
    }

    /**
     * Sign in with the ticket's address and token, ending the session of
     * whichever mailbox this browser had open.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function authenticate(array $args): array
    {
        if ($this->credentials === null) {
            return $args;
        }

        rcmail::get_instance()->kill_session();

        $args['user'] = $this->credentials['user'];
        $args['pass'] = $this->credentials['password'];
        $args['cookiecheck'] = false;
        $args['valid'] = true;

        return $args;
    }

    /**
     * For a signed-in session, have Roundcube come back here when its
     * connection to Dovecot fails; if Dovecot refused the sign-in itself
     * (not a server that is down), end the session and go to the login
     * form.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function storage_connect(array $args): array
    {
        $rcmail = rcmail::get_instance();

        if (empty($_SESSION['user_id']) || $rcmail->task === 'login') {
            return $args;
        }

        if (($args['attempt'] ?? 1) === 1) {
            $args['retry'] = true;

            return $args;
        }

        $args['retry'] = false;
        $storage = $rcmail->get_storage();

        if ($storage instanceof rcube_imap && ($storage->conn->resultcode ?? null) === 'AUTHENTICATIONFAILED') {
            $rcmail->kill_session();
            $rcmail->output->redirect(['_task' => 'login'], 0);
        }

        return $args;
    }

    /**
     * The address and token in $ticket, or null unless it is sealed with
     * the panel's key, unexpired, well formed and bound to $binding.
     *
     * @return array{user: string, password: string}|null
     */
    private static function open_ticket(string $ticket, string $binding): ?array
    {
        $key = base64_decode(trim((string) @file_get_contents(self::KEY_FILE)), true);

        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES || strlen($ticket) > 4096) {
            return null;
        }

        try {
            $sealed = sodium_base642bin($ticket, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (SodiumException) {
            return null;
        }

        if (strlen($sealed) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $json = sodium_crypto_secretbox_open(
            substr($sealed, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($sealed, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $key,
        );

        $data = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($data)
            || ! is_string($data['user'] ?? null)
            || ! is_string($data['password'] ?? null)
            || ! is_string($data['binding'] ?? null)
            || ! is_int($data['expires'] ?? null)) {
            return null;
        }

        if ($data['expires'] < time() || $data['expires'] > time() + 300) {
            return null;
        }

        if ($binding === '' || ! hash_equals($data['binding'], $binding)) {
            return null;
        }

        if (strlen($data['user']) > 320
            || preg_match('/^[a-z0-9_+-]+(\.[a-z0-9_+-]+)*@[a-z0-9-]+(\.[a-z0-9-]+)+$/D', $data['user']) !== 1
            || preg_match('/^[0-9a-f]{64}$/D', $data['password']) !== 1) {
            return null;
        }

        return ['user' => $data['user'], 'password' => $data['password']];
    }
}
