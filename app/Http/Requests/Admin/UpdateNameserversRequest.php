<?php

namespace App\Http\Requests\Admin;

use App\Rules\ValidDomain;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateNameserversRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request: the first
     * nameserver, which is also in every zone's SOA, and an optional
     * second one.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ns1' => ['required', 'string', new ValidDomain],
            'ns2' => ['nullable', 'string', new ValidDomain, 'different:ns1'],
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'ns1' => ValidDomain::normalize($this->input('ns1')),
            'ns2' => ValidDomain::normalize($this->input('ns2')),
        ]);
    }
}
