<?php

namespace Tests\Feature\Domains;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Enums\SslSource;
use App\Enums\SslStatus;
use App\Jobs\RunAgentTask;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Package;
use App\Models\Task;
use App\Models\User;
use App\Services\DomainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomCertificateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_accounts_can_install_a_certificate_of_their_own()
    {
        $account = User::factory()->create(['username' => 'budi']);
        $domain = Domain::factory()->for($account, 'account')->create(['name' => 'example.com']);
        [$certificate, $key] = $this->certificate('example.com');

        $this->actingAs($account)
            ->from(route('domains.index'))
            ->post(route('domains.certificate.store', $domain), ['certificate' => $certificate, 'private_key' => $key])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('domains.index'));

        $this->assertSame(SslStatus::Pending, $domain->fresh()->ssl_status);

        $task = Task::query()->sole();

        $this->assertSame('ssl.install', $task->action);
        // As submitted, without the surrounding whitespace.
        $this->assertSame([
            'username' => 'budi',
            'domain' => 'example.com',
            'certificate' => trim($certificate),
            'private_key' => trim($key),
        ], $task->payload);

        $log = AuditLog::query()->where('action', 'domain.ssl_custom_installed')->sole();

        $this->assertSame($account->id, $log->actor_id);
        $this->assertStringNotContainsString('PRIVATE KEY', (string) json_encode($log->changes));
    }

    public function test_an_installed_certificate_is_recorded_and_served_and_its_key_leaves_the_task()
    {
        $account = User::factory()->create(['username' => 'budi']);
        $domain = Domain::factory()->for($account, 'account')->create(['name' => 'example.com']);
        [$certificate, $key] = $this->certificate('example.com');

        $this->actingAs($account)->post(route('domains.certificate.store', $domain), ['certificate' => $certificate, 'private_key' => $key]);

        $this->runTask(Task::query()->sole(), [
            'names' => ['example.com', 'www.example.com'],
            'expires_at' => '2027-11-09T00:00:00Z',
            'issuer' => 'Cloudflare Origin SSL Certificate Authority',
            'trusted' => false,
        ]);

        $domain->refresh();

        $this->assertSame(SslStatus::Active, $domain->ssl_status);
        $this->assertSame(SslSource::Custom, $domain->ssl_source);
        $this->assertSame(['example.com', 'www.example.com'], $domain->ssl_names);
        $this->assertSame('Cloudflare Origin SSL Certificate Authority', $domain->ssl_issuer);
        $this->assertFalse($domain->ssl_trusted);
        $this->assertSame('2027-11-09', $domain->ssl_expires_at->utc()->toDateString());

        // The website is served with it from now on.
        $apply = Task::query()->where('action', 'web.apply_domain')->sole();
        $this->assertTrue($apply->payload['ssl']);
        $this->assertSame('custom', $apply->payload['certificate']);

        // Only the agent ever needed the key.
        $install = Task::query()->where('action', 'ssl.install')->sole();
        $this->assertSame('[removed]', $install->payload['private_key']);
        $this->assertSame('[removed]', $install->payload['certificate']);
        $this->assertSame('example.com', $install->payload['domain']);
    }

    public function test_a_lets_encrypt_certificate_keeps_serving_while_a_custom_one_replaces_it()
    {
        $account = User::factory()->create(['username' => 'budi']);
        $domain = Domain::factory()->for($account, 'account')->sslActive(forceHttps: true)->create([
            'name' => 'example.com',
            'ssl_source' => SslSource::LetsEncrypt,
        ]);
        [$certificate, $key] = $this->certificate('example.com');

        $this->actingAs($account)->post(route('domains.certificate.store', $domain), ['certificate' => $certificate, 'private_key' => $key]);

        $domain->refresh();
        $this->assertSame(SslStatus::Replacing, $domain->ssl_status);

        // Any reconfiguration meanwhile still serves HTTPS with Let's Encrypt.
        $this->app->make(DomainService::class)->reapply($domain, $account);
        $apply = Task::query()->where('action', 'web.apply_domain')->sole();
        $this->assertTrue($apply->payload['ssl']);
        $this->assertTrue($apply->payload['force_https']);
        $this->assertArrayNotHasKey('certificate', $apply->payload);

        // A refused replacement leaves the website as it was, with the reason.
        $this->runTask(Task::query()->where('action', 'ssl.install')->sole(), new AgentException('the certificate does not cover example.com'));

        $domain->refresh();
        $this->assertSame(SslStatus::Active, $domain->ssl_status);
        $this->assertSame(SslSource::LetsEncrypt, $domain->ssl_source);
        $this->assertSame('the certificate does not cover example.com', $domain->ssl_error);
    }

    public function test_a_refused_first_certificate_fails_the_ssl_of_the_domain()
    {
        $account = User::factory()->create();
        $domain = Domain::factory()->for($account, 'account')->create(['name' => 'example.com']);
        [$certificate, $key] = $this->certificate('example.com');

        $this->actingAs($account)->post(route('domains.certificate.store', $domain), ['certificate' => $certificate, 'private_key' => $key]);
        $this->runTask(Task::query()->sole(), new AgentException('the private key cannot be read'));

        $domain->refresh();
        $this->assertSame(SslStatus::Failed, $domain->ssl_status);
        $this->assertNull($domain->ssl_source);
        $this->assertSame('the private key cannot be read', $domain->ssl_error);
        $this->assertSame('[removed]', Task::query()->sole()->payload['private_key']);
    }

    public function test_a_wildcard_certificate_covers_a_subdomain()
    {
        $account = User::factory()->create();
        $parent = Domain::factory()->for($account, 'account')->create(['name' => 'example.com']);
        $subdomain = Domain::factory()->subdomainOf($parent, 'blog')->create();
        [$certificate, $key] = $this->certificate('*.example.com');

        $this->actingAs($account)
            ->post(route('domains.certificate.store', $subdomain), ['certificate' => $certificate, 'private_key' => $key])
            ->assertSessionHasNoErrors();

        $this->actingAs($account)
            ->post(route('domains.certificate.store', $parent), ['certificate' => $certificate, 'private_key' => $key])
            ->assertSessionHasErrors(['certificate' => 'The certificate does not cover example.com.']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unusableCertificates(): array
    {
        return [
            'another key' => ['another key', 'private_key'],
            'another domain' => ['another domain', 'certificate'],
            'a key in the certificate field' => ['key as certificate', 'certificate'],
            'not PEM' => ['not PEM', 'certificate'],
            'an encrypted key' => ['encrypted key', 'private_key'],
            'two keys' => ['two keys', 'private_key'],
            'a weak RSA key' => ['weak key', 'private_key'],
            'no key' => ['no key', 'private_key'],
        ];
    }

    #[DataProvider('unusableCertificates')]
    public function test_unusable_certificates_are_refused_right_away(string $case, string $field)
    {
        $account = User::factory()->create();
        $domain = Domain::factory()->for($account, 'account')->create(['name' => 'example.com']);
        [$certificate, $key] = $this->certificate('example.com');

        [$certificate, $key] = match ($case) {
            'another key' => [$certificate, $this->certificate('example.com')[1]],
            'another domain' => $this->certificate('example.org'),
            'key as certificate' => [$certificate.$key, $key],
            'not PEM' => ['MIIBkTCB+wIJAKHBf...', $key],
            'encrypted key' => [$certificate, "-----BEGIN ENCRYPTED PRIVATE KEY-----\nAAAA\n-----END ENCRYPTED PRIVATE KEY-----"],
            'two keys' => [$certificate, $key.$key],
            'weak key' => $this->certificate('example.com', rsaBits: 1024),
            'no key' => [$certificate, ''],
        };

        $this->actingAs($account)
            ->from(route('domains.index'))
            ->post(route('domains.certificate.store', $domain), ['certificate' => $certificate, 'private_key' => $key])
            ->assertSessionHasErrors($field)
            // A refused private key never lingers in the session.
            ->assertSessionMissing('_old_input.private_key');

        $this->assertNull($domain->fresh()->ssl_status);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_expired_and_not_yet_valid_certificates_are_refused()
    {
        $account = User::factory()->create();
        $domain = Domain::factory()->for($account, 'account')->create(['name' => 'example.com']);
        [$certificate, $key] = $this->certificate('example.com', days: 30);

        $this->travel(31)->days();

        $this->actingAs($account)
            ->post(route('domains.certificate.store', $domain), ['certificate' => $certificate, 'private_key' => $key])
            ->assertSessionHasErrors('certificate');

        $this->travel(-62)->days();

        $this->actingAs($account)
            ->post(route('domains.certificate.store', $domain), ['certificate' => $certificate, 'private_key' => $key])
            ->assertSessionHasErrors('certificate');

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_certificates_need_an_active_domain_of_the_account_with_ssl_in_its_package()
    {
        $account = User::factory()->create(['package_id' => Package::factory()->create(['ssl' => false])->id]);
        [$certificate, $key] = $this->certificate('example.com');
        $data = ['certificate' => $certificate, 'private_key' => $key];

        $noSsl = Domain::factory()->for($account, 'account')->create(['name' => 'example.com']);
        $this->actingAs($account)->post(route('domains.certificate.store', $noSsl), $data)
            ->assertSessionHasErrors(['certificate' => 'SSL is not included in your package.']);

        $other = User::factory()->create();
        $pending = Domain::factory()->for($other, 'account')->pending()->create(['name' => 'example.net']);
        $busy = Domain::factory()->for($other, 'account')->sslPending()->create(['name' => 'example.org']);

        foreach ([$pending, $busy] as $domain) {
            [$certificate, $key] = $this->certificate($domain->name);

            $this->actingAs($other)
                ->post(route('domains.certificate.store', $domain), ['certificate' => $certificate, 'private_key' => $key])
                ->assertSessionHasErrors();
        }

        $this->actingAs(User::factory()->create())
            ->post(route('domains.certificate.store', $noSsl), $data)
            ->assertNotFound();

        $this->assertDatabaseCount('tasks', 0);
    }

    /**
     * A self-signed certificate for commonName, valid from now for days,
     * with its private key: EC P-256, or RSA with rsaBits bits.
     *
     * @return array{string, string}
     */
    private function certificate(string $commonName, int $days = 365, ?int $rsaBits = null): array
    {
        $key = openssl_pkey_new($rsaBits === null
            ? ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']
            : ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => $rsaBits]);

        $request = openssl_csr_new(['commonName' => $commonName], $key, ['digest_alg' => 'sha256']);
        $certificate = openssl_csr_sign($request, null, $key, $days, ['digest_alg' => 'sha256']);

        openssl_x509_export($certificate, $certificatePem);
        openssl_pkey_export($key, $keyPem);

        return [$certificatePem, $keyPem];
    }

    /**
     * Run the task against a mocked agent that returns output, or throws.
     *
     * @param  array<string, mixed>|AgentException  $result
     */
    private function runTask(Task $task, array|AgentException $result): void
    {
        $agent = Mockery::mock(AgentClient::class);
        $call = $agent->shouldReceive('call')->once();

        $result instanceof AgentException ? $call->andThrow($result) : $call->andReturn($result);

        (new RunAgentTask($task))->handle($agent);
    }
}
