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

];
