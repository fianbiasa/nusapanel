<?php

namespace App\Http\Requests;

use App\Support\WebSettings;
use Illuminate\Contracts\Validation\ValidationRule;

class StoreProtectedLoginRequest extends WebsiteSettingsRequest
{
    /**
     * Get the validation rules that apply to the request: the name and
     * password of the login.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'regex:'.WebSettings::LOGIN_PATTERN],
            'password' => ['required', 'string', 'min:12', 'max:128'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'username.regex' => __('The name may have up to 64 letters, digits and ._-.'),
        ];
    }
}
