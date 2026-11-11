<?php

namespace App\Http\Requests;

use App\Models\Mailbox;
use App\Models\MailFilter;
use App\Rules\ValidMailAddresses;
use App\Support\MailAddress;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreMailFilterRequest extends FormRequest
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
     * Get the validation rules that apply to the request: what is checked
     * (from, to, subject), how (contains, is), the text (one line), and
     * what happens: move to a folder (a plain folder name), forward to an
     * address, or delete.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $action = $this->input('action');

        return [
            'field' => ['required', Rule::in(MailFilter::FIELDS)],
            'operator' => ['required', Rule::in(MailFilter::OPERATORS)],
            'value' => ['required', 'string', 'max:200', 'regex:/^[^\x00-\x1f\x7f]+$/u'],
            'action' => ['required', Rule::in(MailFilter::ACTIONS)],
            'target' => match ($action) {
                'move' => ['required', 'string', 'max:64', 'regex:/^[^\x00-\x1f\x7f\/"\\\\.][^\x00-\x1f\x7f\/"\\\\]*$/u'],
                'forward' => ['required', 'string', 'max:254', new ValidMailAddresses],
                default => ['nullable'],
            },
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
            'value.regex' => __('The text must be one line.'),
            'target.required' => __('Enter the folder or address.'),
            'target.regex' => __('Use a folder name without slashes, quotes or a leading dot.'),
        ];
    }

    /**
     * The filter as it is stored.
     *
     * @return array{field: string, operator: string, value: string, action: string, target: string|null}
     */
    public function filter(): array
    {
        $action = $this->string('action')->toString();

        return [
            'field' => $this->string('field')->toString(),
            'operator' => $this->string('operator')->toString(),
            'value' => $this->string('value')->toString(),
            'action' => $action,
            'target' => $action === 'discard' ? null : $this->string('target')->toString(),
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $value = $this->input('value');
        $target = $this->input('target');

        $this->merge([
            'value' => is_string($value) ? trim($value) : $value,
            'target' => match (true) {
                ! is_string($target) => $target,
                $this->input('action') === 'forward' => MailAddress::normalize($target),
                default => trim($target),
            },
        ]);
    }
}
