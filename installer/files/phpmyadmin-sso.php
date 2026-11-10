<?php

/**
 * Single sign-on from rayxo into phpMyAdmin.
 *
 * The panel gives the account's MariaDB owner user a fresh password and
 * posts a ticket here: the user, that password, an expiry a minute away and
 * a binding value, sealed with the key in /etc/rayxo-pma/sso.key (the
 * panel's PHPMYADMIN_SSO_KEY). The panel also set the binding value as a
 * cookie for /phpmyadmin/ in the same browser, so a ticket only works in the
 * browser that asked for it. This script opens phpMyAdmin's signon session
 * with the credentials and sends the browser on to phpMyAdmin.
 *
 * GET ?logout=1 is phpMyAdmin's LogoutURL: it ends the signon session and
 * returns to the panel.
 *
 * build-release.sh copies this file into the release as
 * phpmyadmin/rayxo-sso.php.
 */

declare(strict_types=1);

const RAYXO_SSO_KEY_FILE = '/etc/rayxo-pma/sso.key';
const RAYXO_SSO_BINDING_COOKIE = 'rayxo_pma_binding';

/** @var array<string, mixed> $cfg */
$cfg = [];
require '/etc/rayxo-pma/config.inc.php';

/** @var array{SignonSession: string, SignonCookieParams: array<string, mixed>, SignonURL: string} $server */
$server = $cfg['Servers'][1];

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

/**
 * Send the browser to $url and stop.
 */
function rayxo_sso_redirect(string $url): never
{
    header('Location: '.$url, true, 303);
    exit;
}

/**
 * Expire a cookie of phpMyAdmin's folder.
 */
function rayxo_sso_forget_cookie(string $name): void
{
    setcookie($name, '', [
        'expires' => 1,
        'path' => '/phpmyadmin/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Open phpMyAdmin's signon session, as phpMyAdmin itself does.
 *
 * @param  array{SignonSession: string, SignonCookieParams: array<string, mixed>}  $server
 */
function rayxo_sso_start_session(array $server): void
{
    session_set_cookie_params($server['SignonCookieParams']);
    session_name($server['SignonSession']);
    session_start();
}

/**
 * The credentials in $ticket, or null unless it is sealed with the panel's
 * key, unexpired, well formed and bound to $binding.
 *
 * @return array{user: string, password: string}|null
 */
function rayxo_sso_open_ticket(string $ticket, string $binding): ?array
{
    $key = base64_decode(trim((string) @file_get_contents(RAYXO_SSO_KEY_FILE)), true);

    if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
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

    if (preg_match('/^[a-z][a-z0-9]{2,15}$/', $data['user']) !== 1 || $data['password'] === '') {
        return null;
    }

    return ['user' => $data['user'], 'password' => $data['password']];
}

if (isset($_GET['logout'])) {
    if (isset($_COOKIE[$server['SignonSession']])) {
        rayxo_sso_start_session($server);
        $_SESSION = [];
        session_destroy();
        rayxo_sso_forget_cookie($server['SignonSession']);
    }

    rayxo_sso_redirect($server['SignonURL']);
}

$ticket = $_POST['ticket'] ?? null;
$binding = $_COOKIE[RAYXO_SSO_BINDING_COOKIE] ?? '';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ! is_string($ticket) || ! is_string($binding)) {
    rayxo_sso_redirect($server['SignonURL']);
}

$credentials = rayxo_sso_open_ticket($ticket, $binding);

// The binding works once.
rayxo_sso_forget_cookie(RAYXO_SSO_BINDING_COOKIE);

if ($credentials === null) {
    rayxo_sso_redirect($server['SignonURL']);
}

// A new signon session, and none of phpMyAdmin's state from an earlier one.
rayxo_sso_forget_cookie('phpMyAdmin');
rayxo_sso_forget_cookie('phpMyAdmin_https');

rayxo_sso_start_session($server);
session_regenerate_id(true);
$_SESSION = [
    'PMA_single_signon_user' => $credentials['user'],
    'PMA_single_signon_password' => $credentials['password'],
];
session_write_close();

rayxo_sso_redirect('index.php');
