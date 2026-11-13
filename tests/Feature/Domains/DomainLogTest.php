<?php

namespace Tests\Feature\Domains;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\User;
use App\Services\FileManagerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\TestCase;

class DomainLogTest extends TestCase
{
    use RefreshDatabase;

    private User $account;

    private Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = User::factory()->create(['username' => 'budi']);
        $this->domain = Domain::factory()->for($this->account, 'account')->create(['name' => 'example.com']);
    }

    public function test_the_page_shows_the_end_of_the_chosen_log()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('call')->once()
            ->with('logs.tail', ['username' => 'budi', 'domain' => 'example.com', 'kind' => 'apache-error', 'lines' => 500, 'filter' => 'fatal'], 30)
            ->andReturn(['lines' => ['PHP Fatal error: x'], 'size' => 2048, 'modified_at' => '2026-11-13T03:16:50Z', 'partial' => false]));

        $this->actingAs($this->account)
            ->get(route('domains.logs.show', ['domain' => $this->domain, 'kind' => 'apache-error', 'lines' => 500, 'filter' => 'fatal']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('domains/logs')
                ->where('kind', 'apache-error')
                ->where('lines', 500)
                ->where('filter', 'fatal')
                ->where('log.lines', ['PHP Fatal error: x'])
                ->where('log.size', 2048)
                ->where('log.error', null));
    }

    public function test_a_website_not_set_up_yet_says_so_instead_of_failing()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('call')->once()
            ->andThrow(new AgentException('the account has no website example.com on this server', -32000, reason: 'no_website')));

        $this->actingAs($this->account)
            ->get(route('domains.logs.show', $this->domain))
            ->assertInertia(fn (Assert $page) => $page
                ->where('kind', 'access')
                ->where('lines', 100)
                ->where('log.lines', [])
                ->where('log.error', 'The logs can be read once the website is set up on the server.'));
    }

    public function test_only_the_logs_the_page_offers_can_be_asked_for()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('call'));

        foreach ([['kind' => '../../shadow'], ['lines' => 5000], ['filter' => str_repeat('a', 201)]] as $query) {
            $this->actingAs($this->account)
                ->get(route('domains.logs.show', ['domain' => $this->domain, ...$query]))
                ->assertSessionHasErrors();
        }

        $this->actingAs($this->account)
            ->get(route('domains.logs.download', $this->domain))
            ->assertSessionHasErrors('kind');
    }

    public function test_other_accounts_and_pointers_have_no_logs_here()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('call'));
        $pointer = Domain::factory()->pointerOf($this->domain)->create(['name' => 'example.net']);

        $this->actingAs(User::factory()->create())->get(route('domains.logs.show', $this->domain))->assertNotFound();
        $this->actingAs(User::factory()->create())->get(route('domains.statistics', $this->domain))->assertNotFound();
        $this->actingAs(User::factory()->create())->get(route('domains.logs.download', ['domain' => $this->domain, 'kind' => 'access']))->assertNotFound();
        $this->actingAs($this->account)->get(route('domains.logs.show', $pointer))->assertNotFound();
    }

    public function test_every_kept_log_is_downloaded_gzipped()
    {
        $this->expectAgentWrites('logs.download', 'gzipped log', ['kind' => 'error']);

        $response = $this->actingAs($this->account)
            ->get(route('domains.logs.download', ['domain' => $this->domain, 'kind' => 'error']));

        $response->assertOk()->assertDownload();
        $this->assertMatchesRegularExpression('/filename=example\.com-error-\d{8}\.log\.gz$/', (string) $response->headers->get('content-disposition'));
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'domain.log_downloaded']);
    }

    public function test_the_visitor_report_is_shown_in_a_sandbox()
    {
        $this->expectAgentWrites('stats.report', '<html><script>report()</script></html>');

        $response = $this->actingAs($this->account)->get(route('domains.statistics', $this->domain));

        $response->assertOk();
        $this->assertSame('<html><script>report()</script></html>', $response->streamedContent());
        $this->assertStringStartsWith('text/html', (string) $response->headers->get('content-type'));

        // Without allow-same-origin the report cannot reach the panel's
        // cookies, whatever visitors put in the logs.
        $policy = (string) $response->headers->get('content-security-policy');
        $this->assertStringContainsString('sandbox allow-scripts', $policy);
        $this->assertStringNotContainsString('allow-same-origin', $policy);
        $this->assertStringContainsString("default-src 'none'", $policy);
    }

    public function test_a_website_without_visits_goes_back_with_a_message()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('call')->once()
            ->andThrow(new AgentException('example.com has no visits in its logs yet', -32000, reason: 'no_visits')));

        $this->actingAs($this->account)
            ->from(route('domains.logs.show', $this->domain))
            ->get(route('domains.statistics', $this->domain))
            ->assertRedirect(route('domains.logs.show', $this->domain));
    }

    /**
     * The agent writes content to the download transfer the panel names.
     *
     * @param  array<string, mixed>  $params
     */
    private function expectAgentWrites(string $method, string $content, array $params = []): void
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('call')->once()
            ->withArgs(function (string $called, array $sent) use ($method, $content, $params): bool {
                if ($called !== $method || $sent['username'] !== 'budi' || $sent['domain'] !== 'example.com' || array_diff_assoc($params, $sent) !== []) {
                    return false;
                }

                File::ensureDirectoryExists(FileManagerService::transferDir());
                File::put(FileManagerService::transferPath($sent['transfer'].'.download'), $content);

                return true;
            })
            ->andReturn([]));
    }
}
