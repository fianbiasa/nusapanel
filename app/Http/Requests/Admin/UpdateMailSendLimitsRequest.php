<?php

namespace App\Http\Requests\Admin;

use App\Services\ServerSettingsService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMailSendLimitsRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request: recipients per
     * 24 hours, or empty for no limit.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $limit = ['nullable', 'integer', 'min:1', 'max:'.ServerSettingsService::MAX_MAIL_SEND_LIMIT];

        return [
            'account' => $limit,
            'server' => $limit,
        ];
    }
}
