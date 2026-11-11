<?php

namespace App\Http\Requests;

use App\Models\Mailbox;
use App\Services\MailService;
use App\Services\ServerSettingsService;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateMailboxRequest extends FormRequest
{
    /**
     * Only the account that owns the mailbox; other accounts' mailboxes are
     * reported as missing.
     */
    public function authorize(): Response
    {
        $mailbox = $this->route('mailbox');

        abort_unless($mailbox instanceof Mailbox, 404);

        return Gate::inspect('update', $mailbox);
    }

    /**
     * Get the validation rules that apply to the request: a new password
     * (empty keeps the current one), the quota in megabytes (empty is no
     * limit), the mailbox's daily send limit (empty is the account's; never
     * above it) and whether it is suspended.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $accountLimit = app(ServerSettingsService::class)->mailSendLimits()['account'];

        return [
            'password' => ['nullable', 'string', 'min:12', 'max:128'],
            'quota_mb' => ['nullable', 'integer', 'min:1', 'max:'.MailService::MAX_QUOTA_MB],
            'daily_send_limit' => ['nullable', 'integer', 'min:1', 'max:'.($accountLimit ?? ServerSettingsService::MAX_MAIL_SEND_LIMIT)],
            'suspended' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'daily_send_limit.max' => __('An email account may send to at most :max recipients a day, the limit of the hosting account.'),
        ];
    }
}
