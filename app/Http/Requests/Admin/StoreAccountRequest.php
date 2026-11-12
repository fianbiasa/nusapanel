<?php

namespace App\Http\Requests\Admin;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\Role;
use App\Models\Package;
use App\Models\ResellerPackage;
use App\Models\User;
use App\Rules\ValidDomain;
use App\Rules\ValidUsername;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new account. Resellers only create hosting accounts, on their own
 * packages; administrators also create resellers (on a reseller package)
 * and other administrators. The domain and package go with a hosting
 * account only.
 */
class StoreAccountRequest extends FormRequest
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var User $actor */
        $actor = $this->user();
        $roles = $actor->isAdmin() ? [Role::User, Role::Reseller, Role::Admin] : [Role::User];

        return [
            'username' => ['required', 'string', new ValidUsername, Rule::unique(User::class)],
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'role' => ['required', Rule::in(array_map(fn (Role $role): string => $role->value, $roles))],
            'domain' => ['exclude_unless:role,user', 'nullable', 'string', new ValidDomain],
            'package_id' => [
                'exclude_unless:role,user',
                'nullable',
                'integer',
                Rule::in(Package::query()->handedOutBy($actor)->pluck('id')->all()),
            ],
            'reseller_package_id' => ['exclude_unless:role,reseller', 'nullable', 'integer', Rule::exists(ResellerPackage::class, 'id')],
        ];
    }

    /**
     * Prepare the data for validation: a missing role is a hosting account.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'role' => $this->input('role', Role::User->value),
            'domain' => ValidDomain::normalize($this->input('domain')),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'package_id.in' => __('Choose one of your packages.'),
            'role.in' => __('You cannot create an account of this kind.'),
        ];
    }
}
