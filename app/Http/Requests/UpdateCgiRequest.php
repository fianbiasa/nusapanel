<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class UpdateCgiRequest extends WebsiteSettingsRequest
{
    /**
     * Get the validation rules that apply to the request: whether the
     * scripts in cgi-bin run.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'cgi_enabled' => ['required', 'boolean'],
        ];
    }
}
