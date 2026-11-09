<?php

namespace App\Http\Requests;

use App\Models\Domain;
use App\Support\CustomCertificate;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class StoreCustomCertificateRequest extends FormRequest
{
    /**
     * Only the account that owns the domain; other accounts' domains are
     * reported as missing.
     */
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->domain());
    }

    /**
     * Get the validation rules that apply to the request: the certificate
     * with its intermediates and the private key, both PEM.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'certificate' => ['required', 'string', 'max:'.CustomCertificate::MAX_CERTIFICATE_BYTES],
            'private_key' => ['required', 'string', 'max:'.CustomCertificate::MAX_KEY_BYTES],
        ];
    }

    /**
     * Get the "after" validation callables for the request: the
     * certificate must belong to the key, cover the domain and be valid.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $result = CustomCertificate::inspect(
                    $this->domain()->name,
                    $this->string('certificate')->toString(),
                    $this->string('private_key')->toString(),
                    now()->getTimestamp(),
                );

                if (is_array($result)) {
                    foreach ($result as $field => $message) {
                        $validator->errors()->add($field, $message);
                    }
                }
            },
        ];
    }

    /**
     * The domain the certificate is for.
     */
    public function domain(): Domain
    {
        $domain = $this->route('domain');

        abort_unless($domain instanceof Domain, 404);

        return $domain;
    }
}
