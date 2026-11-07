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

    'default_php_version' => env('NUSAPANEL_PHP_VERSION', '8.4'),

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

    'public_ip' => env('NUSAPANEL_PUBLIC_IP'),

];
