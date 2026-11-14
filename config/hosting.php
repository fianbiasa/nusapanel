<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default PHP version
    |--------------------------------------------------------------------------
    |
    | The PHP-FPM version new domains run on. The agent creates one pool per
    | account and PHP version, so this must be a version installed on the
    | server.
    |
    */

    'default_php_version' => env('RAYXO_PHP_VERSION', '8.4'),

    /*
    |--------------------------------------------------------------------------
    | Public IP address
    |--------------------------------------------------------------------------
    |
    | The IPv4 address customers point their domains' A records to, written
    | by the installer. It is only shown to customers (e.g. before they
    | request an SSL certificate) and never used to reach the server.
    |
    */

    'public_ip' => env('RAYXO_PUBLIC_IP'),

    /*
    |--------------------------------------------------------------------------
    | phpMyAdmin
    |--------------------------------------------------------------------------
    |
    | Where phpMyAdmin is (https://<hostname>/phpmyadmin/) and the key its
    | sign-on tickets are sealed with (32 bytes, base64), both written by the
    | installer; phpMyAdmin has the same key. Without them the Databases page
    | does not offer phpMyAdmin. A session lasts "session_minutes" from the
    | moment it is opened.
    |
    */

    'phpmyadmin' => [
        'url' => env('PHPMYADMIN_URL'),
        'sso_key' => env('PHPMYADMIN_SSO_KEY'),
        'session_minutes' => (int) env('PHPMYADMIN_SESSION_MINUTES', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webmail
    |--------------------------------------------------------------------------
    |
    | Where Roundcube is (https://<hostname>/webmail/) and the key its
    | sign-on tickets are sealed with (32 bytes, base64), both written by the
    | installer; Roundcube has the same key. Without them the Email page does
    | not offer webmail. A session opened from the panel lasts
    | "session_hours"; signing in with the mailbox's password works too.
    | Roundcube keeps each mailbox's settings and contacts in the database
    | of "connection", which the panel clears when a mailbox goes.
    |
    */

    'webmail' => [
        'url' => env('WEBMAIL_URL'),
        'sso_key' => env('WEBMAIL_SSO_KEY'),
        'session_hours' => (int) env('WEBMAIL_SESSION_HOURS', 12),
        'connection' => 'webmail',
    ],

    /*
    |--------------------------------------------------------------------------
    | File manager
    |--------------------------------------------------------------------------
    |
    | Uploads and downloads pass through "transfer_dir": the panel writes an
    | upload there in chunks of "chunk_bytes" and the agent reads it, or the
    | agent writes a download there that the panel sends on. On a server it
    | is /var/lib/rayxo/transfer (owned by root, written by the installer
    | into the environment); the agent is started with the same folder.
    |
    */

    'files' => [
        'transfer_dir' => env('RAYXO_TRANSFER_DIR', storage_path('app/private/transfer')),
        'chunk_bytes' => 8 * 1024 * 1024,
    ],

    /*
    |--------------------------------------------------------------------------
    | Automatic backups
    |--------------------------------------------------------------------------
    |
    | The agent keeps the backups the panel makes of every account in
    | "dir", outside the homes. On a server it is /var/lib/rayxo/backups
    | (owned by root and readable by the panel user, which sends downloads
    | from it); the agent is started with the same folder.
    |
    */

    'backups' => [
        'dir' => env('RAYXO_BACKUP_DIR', storage_path('app/private/backups')),
    ],

];
