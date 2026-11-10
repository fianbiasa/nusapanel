<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateServerTimezoneRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request. The agent checks
     * the name again against the timezones the server knows.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'timezone' => ['required', 'string', 'timezone:all'],
        ];
    }
}
