<?php

namespace App\Http\Requests;

use App\Concerns\CertificateSubjectRules;
use App\Support\CertificateGenerator;
use Illuminate\Contracts\Validation\ValidationRule;

class StoreSelfSignedCertificateRequest extends WebsiteSettingsRequest
{
    use CertificateSubjectRules;

    /**
     * Get the validation rules that apply to the request: the names, key
     * and subject of the certificate, and how many days it is valid.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->certificateSubjectRules(),
            'days' => ['required', 'integer', 'between:1,'.CertificateGenerator::MAX_DAYS],
        ];
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
