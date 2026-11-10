<?php

namespace Tests\Unit\Agent;

use App\Agent\AgentException;
use App\Agent\SocketAgentClient;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the JSON-RPC framing against a one-shot stand-in agent that
 * listens on a real Unix socket in a separate PHP process.
 */
class SocketAgentClientTest extends TestCase
{
    private string $socketPath;

    /**
     * @var resource|null
     */
    private $server = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->socketPath = sys_get_temp_dir().'/rayxo-agent-test-'.bin2hex(random_bytes(4)).'.sock';
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }

        if (file_exists($this->socketPath)) {
            unlink($this->socketPath);
        }

        parent::tearDown();
    }

    public function test_it_returns_the_result_of_a_successful_call(): void
    {
        $this->startAgent('{"jsonrpc":"2.0","id":"{id}","result":{"method":"{method}","echo":{params}}}');

        $result = (new SocketAgentClient($this->socketPath, 5))->call('system.info', ['verbose' => true]);

        $this->assertSame(['method' => 'system.info', 'echo' => ['verbose' => true]], $result);
    }

    public function test_it_sends_empty_params_as_an_object(): void
    {
        $this->startAgent('{"jsonrpc":"2.0","id":"{id}","result":{"params":{params}}}');

        $result = (new SocketAgentClient($this->socketPath, 5))->call('system.info');

        $this->assertSame(['params' => []], $result);
    }

    public function test_it_throws_the_error_reported_by_the_agent(): void
    {
        $this->startAgent('{"jsonrpc":"2.0","id":"{id}","error":{"code":-32001,"message":"Domain already exists."}}');

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('Domain already exists.');
        $this->expectExceptionCode(-32001);

        (new SocketAgentClient($this->socketPath, 5))->call('web.apply_domain');
    }

    public function test_it_reports_errors_the_agent_sends_before_reading_the_request(): void
    {
        $this->startAgent('{"jsonrpc":"2.0","id":null,"error":{"code":-32003,"message":"this process is not allowed to use the agent"}}');

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('not allowed to use the agent');
        $this->expectExceptionCode(-32003);

        (new SocketAgentClient($this->socketPath, 5))->call('system.info');
    }

    public function test_it_rejects_a_response_to_a_different_request(): void
    {
        $this->startAgent('{"jsonrpc":"2.0","id":"another-request","result":{}}');

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('does not match the request');

        (new SocketAgentClient($this->socketPath, 5))->call('system.info');
    }

    public function test_it_reports_an_unreachable_agent(): void
    {
        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('unreachable');

        (new SocketAgentClient($this->socketPath, 1))->call('system.info');
    }

    public function test_a_per_call_timeout_overrides_the_default(): void
    {
        $this->startAgent('{"jsonrpc":"2.0","id":"{id}","result":{"done":true}}', delaySeconds: 2);

        $result = (new SocketAgentClient($this->socketPath, 1))->call('ssl.issue', [], 5);

        $this->assertSame(['done' => true], $result);
    }

    public function test_it_gives_up_after_the_default_timeout(): void
    {
        $this->startAgent('{"jsonrpc":"2.0","id":"{id}","result":{}}', delaySeconds: 2);

        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('did not respond within 1 seconds');

        (new SocketAgentClient($this->socketPath, 1))->call('system.info');
    }

    /**
     * Start a stand-in agent that answers exactly one request, after
     * $delaySeconds.
     *
     * The template may use {id}, {method} and {params} placeholders.
     */
    private function startAgent(string $responseTemplate, int $delaySeconds = 0): void
    {
        $script = <<<'PHP'
            [$socketPath, $template, $delay] = [$argv[1], $argv[2], (int) $argv[3]];
            $server = stream_socket_server('unix://'.$socketPath, $errorCode, $errorMessage);
            if ($server === false) {
                fwrite(STDERR, $errorMessage);
                exit(1);
            }
            $connection = stream_socket_accept($server, 10);
            $request = json_decode(fgets($connection), true);
            sleep($delay);
            fwrite($connection, strtr($template, [
                '{id}' => $request['id'],
                '{method}' => $request['method'],
                '{params}' => json_encode($request['params']),
            ])."\n");
            fclose($connection);
            PHP;

        $this->server = proc_open(
            [PHP_BINARY, '-r', $script, '--', $this->socketPath, $responseTemplate, (string) $delaySeconds],
            [],
            $pipes,
        );

        $deadline = microtime(true) + 5;

        while (! file_exists($this->socketPath)) {
            if (microtime(true) > $deadline) {
                $this->fail('The stand-in agent did not start.');
            }

            usleep(10_000);
        }
    }
}
