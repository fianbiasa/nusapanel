<?php

namespace App\Services;

use App\Enums\DomainStatus;
use App\Models\CertificateRequest;
use App\Models\Domain;
use App\Models\User;
use App\Support\AccountLimits;
use App\Support\CertificateGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Makes keys for a website's certificates: a certificate signing request
 * (CSR) to take to a certificate authority, whose key is kept (encrypted)
 * until the signed certificate is installed, or a self-signed certificate
 * that is installed right away like any certificate the customer brings.
 */
class CertificateRequestService
{
    public function __construct(
        private DomainService $domains,
        private AuditLogger $audit,
    ) {}

    /**
     * The host names a certificate for the website covers: the domain,
     * with www. or with every name one label below it.
     *
     * @return non-empty-list<string>
     */
    public static function names(Domain $domain, bool $www, bool $wildcard): array
    {
        return match (true) {
            $wildcard => [$domain->name, '*.'.$domain->name],
            $www => [$domain->name, 'www.'.$domain->name],
            default => [$domain->name],
        };
    }

    /**
     * Make a new key and CSR for the website, replacing the one it had.
     *
     * @param  array<string, string>  $subject
     *
     * @throws ValidationException
     */
    public function create(Domain $domain, array $subject, string $keyType, bool $www, bool $wildcard, User $actor): CertificateRequest
    {
        $this->ensureCanHaveCertificates($domain, 'key_type');

        $names = self::names($domain, $www, $wildcard);
        $generated = CertificateGenerator::request($names, $subject, $keyType);

        return DB::transaction(function () use ($domain, $names, $subject, $keyType, $generated, $actor): CertificateRequest {
            $request = CertificateRequest::query()->where('domain_id', $domain->id)->first() ?? new CertificateRequest;
            $request->fill(['names' => $names, 'subject' => $subject, 'key_type' => $keyType, 'csr' => $generated['csr']]);
            $request->forceFill(['domain_id' => $domain->id, 'private_key' => $generated['key']])->save();

            $this->audit->record('domain.csr_created', $domain, [
                'domain' => $domain->name,
                'names' => $names,
                'key_type' => $keyType,
                'username' => $domain->account->username,
            ], $actor);

            return $request;
        });
    }

    /**
     * Forget the website's CSR and its key.
     */
    public function delete(Domain $domain, User $actor): void
    {
        DB::transaction(function () use ($domain, $actor): void {
            if ($domain->certificateRequest()->delete() === 0) {
                return;
            }

            $this->audit->record('domain.csr_deleted', $domain, [
                'domain' => $domain->name,
                'username' => $domain->account->username,
            ], $actor);
        });
    }

    /**
     * Make a self-signed certificate for the website and install it.
     * Browsers warn about it; it suits testing, or a service that trusts
     * it explicitly.
     *
     * @param  array<string, string>  $subject
     *
     * @throws ValidationException
     */
    public function installSelfSigned(Domain $domain, array $subject, string $keyType, bool $www, bool $wildcard, int $days, User $actor): void
    {
        $this->ensureCanHaveCertificates($domain, 'days');

        $generated = CertificateGenerator::selfSigned(self::names($domain, $www, $wildcard), $subject, $keyType, $days);

        $this->domains->installCustomCertificate($domain, $generated['certificate'], $generated['key'], $actor);
    }

    /**
     * @throws ValidationException
     */
    private function ensureCanHaveCertificates(Domain $domain, string $errorKey): void
    {
        if ($domain->status !== DomainStatus::Active) {
            throw ValidationException::withMessages([
                $errorKey => __('Certificates can only be made for active websites.'),
            ]);
        }

        if (! AccountLimits::for($domain->account)->allowsSsl()) {
            throw ValidationException::withMessages([
                $errorKey => __('SSL is not included in your package.'),
            ]);
        }
    }
}
