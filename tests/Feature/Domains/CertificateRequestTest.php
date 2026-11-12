<?php

namespace Tests\Feature\Domains;

use App\Enums\SslStatus;
use App\Models\AuditLog;
use App\Models\CertificateRequest;
use App\Models\Domain;
use App\Models\Package;
use App\Models\Task;
use App\Models\User;
use App\Support\CustomCertificate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CertificateRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $account;

    private Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->account = User::factory()->create(['username' => 'budi']);
        $this->domain = Domain::factory()->for($this->account, 'account')->create(['name' => 'example.com']);
    }

    public function test_a_csr_is_made_with_a_key_that_is_kept_encrypted_and_never_shown()
    {
        $this->actingAs($this->account)
            ->post(route('domains.certificate-request.store', $this->domain), [
                'key_type' => 'ec256',
                'www' => '1',
                'organization' => ' Toko Budi ',
                'country' => 'id',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $request = CertificateRequest::query()->sole();
        $this->assertSame(['example.com', 'www.example.com'], $request->names);
        $this->assertSame(['organization' => 'Toko Budi', 'country' => 'ID'], $request->subject);

        $subject = openssl_csr_get_subject($request->csr);
        $this->assertSame('example.com', $subject['CN']);
        $this->assertSame('Toko Budi', $subject['O']);
        $this->assertSame('ID', $subject['C']);

        // The CSR belongs to the key, which is only stored encrypted.
        $this->assertSame(
            openssl_pkey_get_details(openssl_csr_get_public_key($request->csr))['key'],
            openssl_pkey_get_details(openssl_pkey_get_private($request->private_key))['key'],
        );
        $this->assertStringNotContainsString('PRIVATE KEY', (string) DB::table('certificate_requests')->value('private_key'));
        $this->assertStringNotContainsString('PRIVATE KEY', (string) json_encode(AuditLog::query()->where('action', 'domain.csr_created')->sole()->changes));

        $this->actingAs($this->account)
            ->get(route('domains.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('domains.0.certificate_request.names', ['example.com', 'www.example.com'])
                ->where('domains.0.certificate_request.csr', $request->csr)
                ->missing('domains.0.certificate_request.private_key'));

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_a_new_csr_replaces_the_old_one_and_a_csr_can_be_deleted()
    {
        $this->actingAs($this->account)->post(route('domains.certificate-request.store', $this->domain), ['key_type' => 'rsa2048', 'www' => '1']);
        $first = CertificateRequest::query()->sole();

        $this->actingAs($this->account)
            ->post(route('domains.certificate-request.store', $this->domain), ['key_type' => 'ec256', 'wildcard' => '1'])
            ->assertSessionHasNoErrors();

        $second = CertificateRequest::query()->sole();
        $this->assertSame(['example.com', '*.example.com'], $second->names);
        $this->assertNotSame($first->private_key, $second->private_key);

        $this->actingAs($this->account)
            ->delete(route('domains.certificate-request.destroy', $this->domain))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('certificate_requests', 0);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'domain.csr_deleted', 'subject_id' => $this->domain->id]);
    }

    public function test_the_certificate_signed_for_the_csr_is_installed_with_its_stored_key()
    {
        $this->actingAs($this->account)->post(route('domains.certificate-request.store', $this->domain), ['key_type' => 'rsa2048', 'www' => '1']);
        $request = CertificateRequest::query()->sole();
        $certificate = $this->signedByTestAuthority($request->csr);

        $this->actingAs($this->account)
            ->post(route('domains.certificate.store', $this->domain), ['certificate' => $certificate])
            ->assertSessionHasNoErrors();

        $task = Task::query()->sole();
        $this->assertSame('ssl.install', $task->action);
        $this->assertSame($request->private_key, $task->payload['private_key']);

        // A certificate of another key is refused.
        openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048]), $otherKey);
        $request->forceFill(['private_key' => $otherKey])->save();
        $this->domain->forceFill(['ssl_status' => null])->save();

        $this->actingAs($this->account)
            ->post(route('domains.certificate.store', $this->domain), ['certificate' => $certificate])
            ->assertSessionHasErrors('private_key');

        // Without a CSR the key must be sent.
        $request->delete();

        $this->actingAs($this->account)
            ->post(route('domains.certificate.store', $this->domain), ['certificate' => $certificate])
            ->assertSessionHasErrors('private_key');

        $this->assertDatabaseCount('tasks', 1);
    }

    public function test_a_self_signed_certificate_is_made_and_installed()
    {
        $this->actingAs($this->account)
            ->post(route('domains.self-signed-certificate.store', $this->domain), [
                'key_type' => 'rsa2048',
                'wildcard' => '1',
                'days' => '30',
            ])
            ->assertSessionHasNoErrors();

        $task = Task::query()->sole();
        $this->assertSame('ssl.install', $task->action);
        $this->assertSame(SslStatus::Pending, $this->domain->fresh()->ssl_status);

        $certificate = CustomCertificate::inspect('example.com', $task->payload['certificate'], $task->payload['private_key'], now()->getTimestamp());
        $this->assertInstanceOf(CustomCertificate::class, $certificate);
        $this->assertSame(['example.com', '*.example.com'], $certificate->names);
        $this->assertEqualsWithDelta(now()->addDays(30)->getTimestamp(), $certificate->expiresAt, 60);

        // It has a key of its own; no CSR is left behind.
        $this->assertDatabaseCount('certificate_requests', 0);
    }

    /**
     * @param  array<string, string>  $data
     */
    #[DataProvider('invalidFields')]
    public function test_invalid_fields_are_refused(array $data, string $field)
    {
        $this->actingAs($this->account)
            ->post(route('domains.self-signed-certificate.store', $this->domain), [...['key_type' => 'rsa2048', 'days' => '365'], ...$data])
            ->assertSessionHasErrors($field);

        $this->assertDatabaseCount('tasks', 0);
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function invalidFields(): array
    {
        return [
            'a weak key' => [['key_type' => 'rsa1024'], 'key_type'],
            'a country name' => [['country' => 'Indonesia'], 'country'],
            'a newline in the subject' => [['organization' => "Toko\nCN=evil.com"], 'organization'],
            'a long organization' => [['organization' => str_repeat('a', 65)], 'organization'],
            'no days' => [['days' => '0'], 'days'],
            'too many days' => [['days' => '3651'], 'days'],
        ];
    }

    public function test_certificates_need_an_active_website_of_the_account_with_ssl_in_its_package()
    {
        $this->actingAs(User::factory()->create())
            ->post(route('domains.certificate-request.store', $this->domain), ['key_type' => 'rsa2048'])
            ->assertNotFound();

        $pending = Domain::factory()->for($this->account, 'account')->pending()->create();

        $this->actingAs($this->account)
            ->post(route('domains.certificate-request.store', $pending), ['key_type' => 'rsa2048'])
            ->assertSessionHasErrors('key_type');

        $this->account->forceFill(['package_id' => Package::factory()->create(['ssl' => false])->id])->save();

        $this->actingAs($this->account)
            ->post(route('domains.self-signed-certificate.store', $this->domain), ['key_type' => 'rsa2048', 'days' => '365'])
            ->assertSessionHasErrors(['days' => 'SSL is not included in your package.']);

        $this->assertDatabaseCount('certificate_requests', 0);
        $this->assertDatabaseCount('tasks', 0);
    }

    /**
     * A certificate for the CSR, signed by a certificate authority made
     * for the test.
     */
    private function signedByTestAuthority(string $csr): string
    {
        $authorityKey = openssl_pkey_new(['private_key_bits' => 2048]);
        $authority = openssl_csr_sign(openssl_csr_new(['commonName' => 'Test CA'], $authorityKey), null, $authorityKey, 30);
        $certificate = openssl_csr_sign($csr, $authority, $authorityKey, 30, serial: 2);
        openssl_x509_export($certificate, $pem);

        return $pem;
    }
}
