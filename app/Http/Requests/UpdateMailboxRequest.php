<?php

namespace App\Http\Requests;

use App\Models\Mailbox;
use App\Services\MailService;
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
     * (empty keeps the current one) and the quota in megabytes (empty is no
     * limit).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password' => ['nullable', 'string', 'min:12', 'max:128'],
            'quota_mb' => ['nullable', 'integer', 'min:1', 'max:'.MailService::MAX_QUOTA_MB],
        ];
    }
}
