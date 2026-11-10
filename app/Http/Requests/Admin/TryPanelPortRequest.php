<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class TryPanelPortRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request. The agent refuses
     * ports of other services of the server and ports in use.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'port' => ['required', 'integer', 'between:1024,65535'],
        ];
    }
}
