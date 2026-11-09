<?php

namespace App\Http\Requests;

use App\Support\DatabaseNames;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreDatabaseRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request: the name after
     * "<username>_", and optionally a new user with access to it.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $username = (string) $this->user()?->username;

        return [
            'name' => ['required', ...DatabaseNames::rules($username, DatabaseNames::MAX_DATABASE)],
            'create_user' => ['required', 'boolean'],
            'user_name' => ['exclude_unless:create_user,true,1', 'required', ...DatabaseNames::rules($username, DatabaseNames::MAX_USER)],
            'password' => ['exclude_unless:create_user,true,1', 'required', 'string', 'min:12', 'max:128'],
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
            'user_name.regex' => __('Use only lowercase letters, digits and underscores.'),
        ];
    }

    /**
     * The user to create along with the database, if one was asked for.
     *
     * @return array{name: string, password: string}|null
     */
    public function newUser(): ?array
    {
        if (! $this->boolean('create_user')) {
            return null;
        }

        return [
            'name' => $this->string('user_name')->toString(),
            'password' => $this->string('password')->toString(),
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $username = (string) $this->user()?->username;

        $this->merge([
            'name' => DatabaseNames::normalize($this->input('name'), $username),
            'user_name' => DatabaseNames::normalize($this->input('user_name'), $username),
        ]);
    }
}
