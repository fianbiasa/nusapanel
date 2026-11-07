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

    /*
    |--------------------------------------------------------------------------
    | Timeouts (seconds)
    |--------------------------------------------------------------------------
    |
    | "timeout" is for calls a page waits on, such as system.info.
    | "task_timeout" is for queued tasks, which may take much longer:
    | issuing a certificate checks the domain, then waits for Let's Encrypt.
    |
    */

    'timeout' => (int) env('AGENT_TIMEOUT', 30),

    'task_timeout' => (int) env('AGENT_TASK_TIMEOUT', 300),

];
