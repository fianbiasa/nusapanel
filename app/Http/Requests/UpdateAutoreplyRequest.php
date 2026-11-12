<?php

namespace App\Http\Requests;

use App\Models\Mailbox;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateAutoreplyRequest extends FormRequest
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
     * Get the validation rules that apply to the request: whether it is on,
     * the subject (one line) and body of the reply, the optional first and
     * last day, and how many days one sender waits for the next reply.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'subject' => ['required_if_accepted:enabled', 'nullable', 'string', 'max:150', 'regex:/^[^\x00-\x1f\x7f]*$/uD'],
            'body' => ['required_if_accepted:enabled', 'nullable', 'string', 'max:5000', 'regex:/^[^\x00]*$/uD'],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'interval_days' => ['required', 'integer', 'min:1', 'max:30'],
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
            'subject.regex' => __('The subject must be one line.'),
        ];
    }

    /**
     * The reply as it is stored.
     *
     * @return array{enabled: bool, subject: string, body: string, starts_on: string|null, ends_on: string|null, interval_days: int}
     */
    public function autoreply(): array
    {
        return [
            'enabled' => $this->boolean('enabled'),
            'subject' => trim($this->string('subject')->toString()),
            'body' => trim($this->string('body')->toString()),
            'starts_on' => $this->filled('starts_on') ? $this->string('starts_on')->toString() : null,
            'ends_on' => $this->filled('ends_on') ? $this->string('ends_on')->toString() : null,
            'interval_days' => $this->integer('interval_days'),
        ];
    }
}
