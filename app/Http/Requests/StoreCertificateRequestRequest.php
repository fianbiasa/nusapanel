<?php

namespace App\Http\Requests;

use App\Concerns\CertificateSubjectRules;
use Illuminate\Contracts\Validation\ValidationRule;

class StoreCertificateRequestRequest extends WebsiteSettingsRequest
{
    use CertificateSubjectRules;

    /**
     * Get the validation rules that apply to the request: the names, key
     * and subject of the certificate to ask a certificate authority for.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->certificateSubjectRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'country.regex' => __('Enter the country as two letters, such as ID.'),
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->prepareCertificateSubject();
    }
}
