<?php

namespace Database\Factories;

use App\Models\CertificateRequest;
use App\Models\Domain;
use App\Support\CertificateGenerator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CertificateRequest>
 */
class CertificateRequestFactory extends Factory
{
    /**
     * Define the model's default state: an ECDSA key and CSR for the
     * website and its www. name.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'domain_id' => Domain::factory(),
            'subject' => [],
            'key_type' => 'ec256',
        ];
    }

    /**
     * Make the key and CSR for the website's name once it is known.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (CertificateRequest $request): void {
            $domain = Domain::query()->findOrFail($request->domain_id);
            $names = [$domain->name, 'www.'.$domain->name];
            $generated = CertificateGenerator::request($names, $request->subject, $request->key_type);

            $request->forceFill(['names' => $names, 'csr' => $generated['csr'], 'private_key' => $generated['key']]);
        });
    }
}
