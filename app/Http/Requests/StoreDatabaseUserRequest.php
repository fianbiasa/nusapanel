<?php

namespace App\Http\Requests;

use App\Support\DatabaseNames;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreDatabaseUserRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request: the name after
     * "<username>_", the password, and the databases it may use. Whether
     * those are the account's is checked by the DatabaseService.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', ...DatabaseNames::rules((string) $this->user()?->username, DatabaseNames::MAX_USER)],
            'password' => ['required', 'string', 'min:12', 'max:128'],
            'databases' => ['present', 'array', 'max:100'],
            'databases.*' => ['integer', 'distinct'],
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
            'name.regex' => __('Use only lowercase letters, digits and underscores.'),
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => DatabaseNames::normalize($this->input('name'), (string) $this->user()?->username),
            'databases' => $this->input('databases', []),
        ]);
    }
}
