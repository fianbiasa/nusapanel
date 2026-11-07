<?php

namespace App\Agent;

use Illuminate\Support\Str;
use JsonException;

/**
 * Talks to nusad over its Unix socket using JSON-RPC 2.0, one request and one
 * response per line. Uses no exec/shell functions, so it keeps working with
 * those functions disabled for the panel's PHP pool.
 */
final class SocketAgentClient implements AgentClient
{
    public function __construct(
        private readonly string $socketPath,
        private readonly int $timeoutSeconds,
    ) {}

    public function call(string $method, array $params = []): array
    {
        $id = (string) Str::uuid();

        $line = $this->exchange($this->encode($id, $method, $params));

        try {
            $response = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AgentException('The agent returned a response that is not valid JSON.');
        }

        if (! is_array($response)) {
            throw new AgentException('The agent response does not match the request.');
        }

        $responseId = $response['id'] ?? null;

        // The agent answers with a null id when it rejects a call before
        // reading it (e.g. the caller is not allowed), so surface that error.
        if ($responseId !== $id && ! ($responseId === null && isset($response['error']))) {
            throw new AgentException('The agent response does not match the request.');
        }

        if (isset($response['error'])) {
            $error = is_array($response['error']) ? $response['error'] : [];

            throw new AgentException(
                (string) ($error['message'] ?? 'The agent reported an unknown error.'),
                (int) ($error['code'] ?? 0),
            );
        }

        $result = $response['result'] ?? null;

        if ($result === null) {
            return [];
        }

        if (! is_array($result)) {
            throw new AgentException('The agent returned a result that is not an object.');
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function encode(string $id, string $method, array $params): string
    {
        try {
            return json_encode([
                'jsonrpc' => '2.0',
                'id' => $id,
                'method' => $method,
                'params' => (object) $params,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        } catch (JsonException $exception) {
            throw new AgentException('The agent request could not be encoded: '.$exception->getMessage());
        }
    }

    private function exchange(string $request): string
    {
        $connection = @stream_socket_client(
            'unix://'.$this->socketPath,
            $errorCode,
            $errorMessage,
            $this->timeoutSeconds,
        );

        if ($connection === false) {
            throw new AgentException("The agent is unreachable at {$this->socketPath}: {$errorMessage}");
        }

        try {
            stream_set_timeout($connection, $this->timeoutSeconds);

            if (@fwrite($connection, $request) === false) {
                throw new AgentException('The request could not be sent to the agent.');
            }

            $line = fgets($connection);

            if ($line === false) {
                $timedOut = stream_get_meta_data($connection)['timed_out'];

                throw new AgentException($timedOut
                    ? "The agent did not respond within {$this->timeoutSeconds} seconds."
                    : 'The agent closed the connection without a response.');
            }

            return $line;
        } finally {
            fclose($connection);
        }
    }
}
