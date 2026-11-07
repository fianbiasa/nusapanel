<?php

namespace App\Http\Requests;

use App\Rules\ValidDomain;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreDomainRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * Whether the name is still free is checked by the DomainService, which
     * does so race-safely while saving.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'domain' => ['required', 'string', new ValidDomain],
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'domain' => ValidDomain::normalize($this->input('domain')),
        ]);
    }
}
