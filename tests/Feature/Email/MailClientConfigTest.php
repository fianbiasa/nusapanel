<?php

namespace Tests\Feature\Email;

use App\Enums\MailDomainMode;
use App\Enums\MailDomainStatus;
use App\Enums\SslStatus;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\Domain;
use App\Models\MailDomain;
use App\Models\User;
use App\Services\DnsService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MailClientConfigTest extends TestCase
{
    use RefreshDatabase;

    private Domain $domain;

    private MailDomain $mailDomain;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['hosting.public_ip' => '203.0.113.10', 'app.url' => 'https://server.example.net:2222']);

        $account = User::factory()->create(['username' => 'budi']);
        $this->domain = Domain::factory()->for($account, 'account')->create(['name' => 'example.com']);
        $this->mailDomain = MailDomain::factory()->for($this->domain)->create(['status' => MailDomainStatus::Active]);
    }

    public function test_thunderbird_gets_the_settings_of_an_address_on_the_server_hostname()
    {
        $response = $this->get('/mail/config-v1.1.xml?emailaddress=Info%40Example.com');

        $response->assertOk()->assertHeader('Content-Type', 'application/xml; charset=utf-8');

        $xml = simplexml_load_string($response->getContent());
        $this->assertSame('example.com', (string) $xml->emailProvider->domain);
        $this->assertSame('server.example.net', (string) $xml->emailProvider->incomingServer[0]->hostname);
        $this->assertSame('993', (string) $xml->emailProvider->incomingServer[0]->port);
        $this->assertSame('%EMAILADDRESS%', (string) $xml->emailProvider->incomingServer[0]->username);
        $this->assertSame('465', (string) $xml->emailProvider->outgoingServer[0]->port);
        $this->assertSame('STARTTLS', (string) $xml->emailProvider->outgoingServer[1]->socketType);
    }

    public function test_mail_dot_domain_is_used_once_it_has_its_certificate_and_the_host_tells_the_domain()
    {
        $this->mailDomain->forceFill(['ssl_status' => SslStatus::Active])->save();

        // Thunderbird asking autoconfig.<domain>, without an address.
        $response = $this->get('http://autoconfig.example.com/.well-known/autoconfig/mail/config-v1.1.xml');

        $response->assertOk();
        $this->assertSame('mail.example.com', (string) simplexml_load_string($response->getContent())->emailProvider->incomingServer[0]->hostname);
    }

    #[DataProvider('unknownAddresses')]
    public function test_nothing_is_told_about_other_domains(string $query)
    {
        MailDomain::factory()->for(Domain::factory()->create(['name' => 'elsewhere.net']))
            ->create(['status' => MailDomainStatus::Active, 'mode' => MailDomainMode::External]);
        MailDomain::factory()->for(Domain::factory()->create(['name' => 'pending.net']))
            ->create(['status' => MailDomainStatus::Pending]);

        $this->get('/mail/config-v1.1.xml?'.$query)->assertNotFound();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unknownAddresses(): array
    {
        return [
            'a domain not hosted here' => ['emailaddress=a@unknown.org'],
            'mail at another service' => ['emailaddress=a@elsewhere.net'],
            'mail not set up yet' => ['emailaddress=a@pending.net'],
            'not an address' => ['emailaddress=example.com'],
            'markup' => ['emailaddress=%3Cx%3E@example.com'],
            'nothing' => [''],
        ];
    }

    public function test_outlook_gets_its_settings_without_a_csrf_token_and_without_xml_parsing()
    {
        $request = '<?xml version="1.0" encoding="utf-8"?>'
            .'<!DOCTYPE x [<!ENTITY secret SYSTEM "file:///etc/passwd">]>'
            .'<Autodiscover xmlns="http://schemas.microsoft.com/exchange/autodiscover/outlook/requestschema/2006">'
            .'<Request><EMailAddress>Sales@example.com</EMailAddress>'
            .'<AcceptableResponseSchema>http://schemas.microsoft.com/exchange/autodiscover/outlook/responseschema/2006a</AcceptableResponseSchema>'
            .'</Request></Autodiscover>';

        $response = $this->call('POST', '/autodiscover/autodiscover.xml', [], [], [], ['CONTENT_TYPE' => 'text/xml'], $request);

        $response->assertOk();
        $this->assertStringNotContainsString('root:', $response->getContent());

        $xml = simplexml_load_string($response->getContent());
        $xml->registerXPathNamespace('a', 'http://schemas.microsoft.com/exchange/autodiscover/outlook/responseschema/2006a');
        $this->assertSame(['IMAP', 'POP3', 'SMTP'], array_map('strval', $xml->xpath('//a:Protocol/a:Type')));
        $this->assertSame('sales@example.com', (string) $xml->xpath('//a:Protocol/a:LoginName')[0]);
        $this->assertSame('server.example.net', (string) $xml->xpath('//a:Protocol/a:Server')[0]);

        $this->call('POST', '/autodiscover/autodiscover.xml', [], [], [], [], '<EMailAddress>a@unknown.org</EMailAddress>')
            ->assertNotFound();
    }

    public function test_outlook_needs_no_csrf_token_on_a_server()
    {
        // Tests skip the CSRF check, so it is the route that must leave it out.
        $route = Route::getRoutes()->getByName('mail-client.autodiscover');

        $this->assertContains(PreventRequestForgery::class, $route->excludedMiddleware());
    }

    public function test_a_domain_that_takes_mail_gets_the_records_mail_apps_look_for()
    {
        $zone = DnsZone::factory()->for($this->domain->account, 'account')->create(['name' => 'example.com', 'domain_id' => $this->domain->id]);
        // A record of the customer's own stays.
        $zone->records()->create(['name' => 'autoconfig.example.com', 'type' => 'CNAME', 'content' => 'mail.example.org', 'ttl' => 3600]);

        app(DnsService::class)->applyMailRecords($this->domain, 'rayxo', 'MIIBtest', null);

        $records = DnsRecord::query()->orderBy('id')->get()->map(fn (DnsRecord $record): string => "{$record->name} {$record->type} {$record->priority} {$record->content}")->all();

        $this->assertContains('autoconfig.example.com CNAME  mail.example.org', $records);
        $this->assertNotContains('autoconfig.example.com A  203.0.113.10', $records);
        $this->assertContains('autodiscover.example.com A  203.0.113.10', $records);
        $this->assertContains('_autodiscover._tcp.example.com SRV 0 0 443 server.example.net', $records);
    }

    public function test_the_upgrade_gives_existing_mail_domains_the_records()
    {
        $zone = DnsZone::factory()->for($this->domain->account, 'account')->create(['name' => 'example.com', 'domain_id' => $this->domain->id]);
        $external = Domain::factory()->for($this->domain->account, 'account')->create(['name' => 'elsewhere.net']);
        MailDomain::factory()->for($external)->create(['status' => MailDomainStatus::Active, 'mode' => MailDomainMode::External]);
        $externalZone = DnsZone::factory()->for($this->domain->account, 'account')->create(['name' => 'elsewhere.net', 'domain_id' => $external->id]);

        app(DnsService::class)->syncAll();
        app(DnsService::class)->syncAll();

        $this->assertSame(1, $zone->records()->where('name', '_autodiscover._tcp.example.com')->count());
        $this->assertSame(1, $zone->records()->where('name', 'autoconfig.example.com')->count());
        $this->assertSame(0, $externalZone->records()->where('name', 'like', '%autodiscover%')->count());
    }
}
