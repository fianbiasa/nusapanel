<?php

namespace App\Agent;

/**
 * The only way the panel changes the server: a call to the root agent (rayxod).
 *
 * Implementations: SocketAgentClient (production, Unix socket) and
 * FakeAgentClient (simulated responses for local development on macOS).
 */
interface AgentClient
{
    /**
     * Call an agent method, e.g. "system.info" or "web.apply_domain".
     *
     * @param  array<string, mixed>  $params
     * @param  int|null  $timeoutSeconds  How long to wait for the answer; null uses the client's default.
     * @return array<string, mixed>
     *
     * @throws AgentException When the agent is unreachable or reports an error.
     */
    public function call(string $method, array $params = [], ?int $timeoutSeconds = null): array;
}
