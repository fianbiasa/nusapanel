<?php

namespace Tests\Feature\Console;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Enums\DomainStatus;
use App\Enums\SslSource;
use App\Models\Domain;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Mockery\MockInterface;
use Tests\TestCase;

class SyncSslExpiryCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_renewed_certificates_get_the_end_date_the_server_has()
    {
        $this->freezeSecond();

        $renewed = $this->domainWithCertificate('example.com', SslSource::LetsEncrypt, now()->addDays(20));
        $onWildcard = Domain::factory()->subdomainOf($renewed)->sslActive()
            ->create(['ssl_source' => SslSource::Parent, 'ssl_expires_at' => now()->addDays(20)]);
        $unreported = Domain::factory()->subdomainOf($renewed, 'shop')->sslActive()
            ->create(['ssl_source' => SslSource::LetsEncrypt, 'ssl_expires_at' => now()->addDays(40)]);
        $current = $this->domainWithCertificate('example.org', SslSource::LetsEncrypt, now()->addDays(60));
        $custom = $this->domainWithCertificate('example.net', SslSource::Custom, now()->addDays(5));
        $this->domainWithCertificate('gone.example', SslSource::LetsEncrypt, now()->addDays(20))
            ->forceFill(['status' => DomainStatus::Deleting])->save();

        $renewedEnd = now()->addDays(90);

        $this->agentReports(['example.com', 'shop.example.com', 'example.org'], [
            'example.com' => $renewedEnd->utc()->toIso8601ZuluString(),
            'example.org' => now()->addDays(60)->utc()->toIso8601ZuluString(),
        ]);

        $this->artisan('rayxo:sync-ssl-expiry')
            ->expectsOutputToContain('New end date stored for 1 certificate.')
            ->assertSuccessful();

        $this->assertTrue($renewed->fresh()->ssl_expires_at->eq($renewedEnd));
        $this->assertTrue($onWildcard->fresh()->ssl_expires_at->eq($renewedEnd));
        $this->assertTrue($unreported->fresh()->ssl_expires_at->eq(now()->addDays(40)));
        $this->assertTrue($current->fresh()->ssl_expires_at->eq(now()->addDays(60)));
        $this->assertTrue($custom->fresh()->ssl_expires_at->eq(now()->addDays(5)));
    }

    public function test_an_unreachable_agent_changes_nothing()
    {
        $this->freezeSecond();
        Exceptions::fake();

        $domain = $this->domainWithCertificate('example.com', SslSource::LetsEncrypt, now()->addDays(20));

        $this->mock(AgentClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('call')->once()->andThrow(new AgentException('Connection refused'));
        });

        $this->artisan('rayxo:sync-ssl-expiry')
            ->expectsOutputToContain('Connection refused')
            ->assertFailed();

        $this->assertTrue($domain->fresh()->ssl_expires_at->eq(now()->addDays(20)));
        Exceptions::assertReported(AgentException::class);
    }

    public function test_it_runs_every_day()
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains((string) $event->command, 'rayxo:sync-ssl-expiry'));

        $this->assertNotNull($event);
        $this->assertSame('30 4 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    private function domainWithCertificate(string $name, SslSource $source, mixed $expiresAt): Domain
    {
        return Domain::factory()->sslActive()->create([
            'name' => $name,
            'ssl_source' => $source,
            'ssl_expires_at' => $expiresAt,
        ]);
    }

    /**
     * @param  list<string>  $askedFor
     * @param  array<string, string>  $endDates
     */
    private function agentReports(array $askedFor, array $endDates): void
    {
        $this->mock(AgentClient::class, function (MockInterface $mock) use ($askedFor, $endDates) {
            $mock->shouldReceive('call')
                ->once()
                ->with('ssl.expiry', ['domains' => $askedFor], config('agent.timeout'))
                ->andReturn(['expires_at' => $endDates]);
        });
    }
}
