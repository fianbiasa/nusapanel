<?php

namespace App\Http\Requests;

use App\Support\FtpDirectory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreFtpAccountRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request: the name before
     * "@<domain>", the domain, the folder relative to the home and the
     * password. Whether the domain is the account's is checked by the
     * FtpService.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:32', 'regex:/^[a-z0-9]([a-z0-9._-]*[a-z0-9])?$/'],
            'domain_id' => ['required', 'integer'],
            'directory' => ['present', ...FtpDirectory::rules()],
            'password' => ['required', 'string', 'min:12', 'max:128'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => __('Use lowercase letters, digits, dots, dashes and underscores, starting and ending with a letter or digit.'),
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $name = $this->input('name');

        $this->merge([
            'name' => is_string($name) ? strtolower(trim($name)) : $name,
            'directory' => FtpDirectory::normalize($this->input('directory')),
        ]);
    }
}
