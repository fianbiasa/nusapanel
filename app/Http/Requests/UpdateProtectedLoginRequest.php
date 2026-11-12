<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class UpdateProtectedLoginRequest extends WebsiteSettingsRequest
{
    /**
     * Get the validation rules that apply to the request: the new password.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'min:12', 'max:128'],
        ];
    }
}
