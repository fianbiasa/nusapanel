<?php

namespace App\Http\Requests;

use App\Services\MailService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreMailboxRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request: the name before
     * "@<domain>" (lowercase letters, digits and _+- with dots between
     * them, as the agent accepts it), the domain, the password and the
     * quota in megabytes (empty is no limit). Whether the domain is the
     * account's is checked by the MailService.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'local_part' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_+-]+(\.[a-z0-9_+-]+)*$/D'],
            'domain_id' => ['required', 'integer'],
            'password' => ['required', 'string', 'min:12', 'max:128'],
            'quota_mb' => ['nullable', 'integer', 'min:1', 'max:'.MailService::MAX_QUOTA_MB],
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
            'local_part.regex' => __('Use lowercase letters, digits and _+- with single dots between them.'),
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $localPart = $this->input('local_part');

        $this->merge(['local_part' => is_string($localPart) ? strtolower(trim($localPart)) : $localPart]);
    }
}
