<?php

namespace App\Concerns;

use App\Support\CertificateGenerator;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * The fields of a new key and certificate (or signing request) for a
 * website: which names it covers, the kind of key, and the organization
 * fields of its subject, all optional.
 */
trait CertificateSubjectRules
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function certificateSubjectRules(): array
    {
        $text = ['nullable', 'string', 'max:64', 'regex:/^[^\x00-\x1F\x7F]*$/u'];

        return [
            'key_type' => ['required', Rule::in(CertificateGenerator::KEY_TYPES)],
            'www' => ['nullable', 'boolean'],
            'wildcard' => ['nullable', 'boolean'],
            'organization' => $text,
            'organizational_unit' => $text,
            'locality' => $text,
            'state' => $text,
            'country' => ['nullable', 'string', 'regex:/^[A-Z]{2}$/'],
            'email' => ['nullable', 'string', 'email', 'max:64'],
        ];
    }

    /**
     * The organization fields that were filled in.
     *
     * @return array<string, string>
     */
    public function subject(): array
    {
        $subject = [];

        foreach (array_keys(CertificateGenerator::SUBJECT_FIELDS) as $field) {
            $value = $this->validated($field);

            if (is_string($value) && $value !== '') {
                $subject[$field] = $value;
            }
        }

        return $subject;
    }

    /**
     * Prepare the subject fields for validation: trimmed, the country in
     * capitals.
     */
    protected function prepareCertificateSubject(): void
    {
        $values = [];

        foreach (array_keys(CertificateGenerator::SUBJECT_FIELDS) as $field) {
            $value = $this->input($field);
            $values[$field] = is_string($value) ? trim($value) : $value;
        }

        if (is_string($values['country'])) {
            $values['country'] = strtoupper($values['country']);
        }

        $this->merge($values);
    }
}
