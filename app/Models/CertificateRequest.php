<?php

namespace App\Models;

use App\Support\CertificateGenerator;
use Database\Factories\CertificateRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The certificate signing request (CSR) a website sent, or will send, to a
 * certificate authority. Its private key stays here, encrypted, so the
 * certificate the authority signs can be installed without it: one per
 * website, replaced by a new request. The key is never shown.
 *
 * @property int $id
 * @property int $domain_id
 * @property list<string> $names The host names the certificate is asked for, the domain first.
 * @property array<string, string> $subject The organization fields ({@see CertificateGenerator::SUBJECT_FIELDS}) that were filled in.
 * @property string $key_type One of {@see CertificateGenerator::KEY_TYPES}.
 * @property string $csr PEM.
 * @property string $private_key PEM, encrypted at rest.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['names', 'subject', 'key_type', 'csr'])]
#[Hidden(['private_key'])]
class CertificateRequest extends Model
{
    /** @use HasFactory<CertificateRequestFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'names' => 'array',
            'subject' => 'array',
            'private_key' => 'encrypted',
        ];
    }

    /**
     * The website the request is for.
     *
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
