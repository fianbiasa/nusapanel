<?php

namespace App\Http\Requests\Admin;

use App\Rules\ValidDomain;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateServerHostnameRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request. The agent checks
     * the name again, and setup.sh its A record and PTR before changing
     * anything.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'hostname' => ['required', 'string', 'max:253', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! ValidDomain::isWellFormed($value)) {
                    $fail(__('The hostname must be a fully qualified name, such as server1.example.com.'));
                }
            }],
        ];
    }

    /**
     * Prepare the data for validation: lowercase, in punycode.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'hostname' => ValidDomain::normalize($this->input('hostname')),
        ]);
    }
}
