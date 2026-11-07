<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Agent driver
    |--------------------------------------------------------------------------
    |
    | "socket" talks to the nusad agent over its Unix socket (servers).
    | "fake" simulates the agent so the panel can be developed on macOS
    | with Herd, where the Linux-only agent cannot run.
    |
    */

    'driver' => env('AGENT_DRIVER', 'socket'),

    'socket' => env('AGENT_SOCKET', '/run/nusapanel/agent.sock'),

    'timeout' => (int) env('AGENT_TIMEOUT', 30),

];
