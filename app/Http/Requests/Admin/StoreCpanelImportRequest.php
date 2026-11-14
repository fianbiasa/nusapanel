<?php

namespace App\Http\Requests\Admin;

use App\Models\Package;
use App\Models\User;
use App\Rules\ValidUsername;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * The import of a cPanel backup in the backups folder: the account made
 * from it may get another username and email address than in cPanel, and
 * always gets a new password (cPanel's cannot move).
 */
class StoreCpanelImportRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var User $actor */
        $actor = $this->user();

        return [
            'file' => ['required', 'string', 'max:160', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]{0,150}\.tar\.gz$/D'],
            'username' => ['nullable', 'string', new ValidUsername, Rule::unique(User::class)],
            'email' => ['nullable', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)],
            'password' => ['required', 'string', Password::default()],
            'package_id' => ['nullable', 'integer', Rule::in(Package::query()->handedOutBy($actor)->pluck('id')->all())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'package_id.in' => __('Choose one of your packages.'),
        ];
    }
}
