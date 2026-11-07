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

];
