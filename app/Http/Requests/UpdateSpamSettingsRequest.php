<?php

namespace App\Http\Requests;

use App\Models\Mailbox;
use App\Models\MailSpamSetting;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateSpamSettingsRequest extends FormRequest
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
     * The sender lists come as text, one sender per line (or separated by
     * commas or spaces): full addresses, or domains with or without "@".
     * They are kept lowercase as "user@domain" and "@domain", once each.
     */
    protected function prepareForValidation(): void
    {
        foreach (['whitelist', 'blacklist'] as $list) {
            $text = $this->input($list);

            if (! is_string($text) && $text !== null) {
                continue;
            }

            $senders = [];

            foreach (preg_split('/[\s,;]+/', strtolower((string) $text)) ?: [] as $sender) {
                if ($sender === '') {
                    continue;
                }

                $senders[] = str_contains($sender, '@') ? $sender : '@'.$sender;
            }

            $this->merge([$list => array_values(array_unique($senders))]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $sender = function (string $attribute, mixed $value, Closure $fail): void {
            $value = (string) $value;
            $domain = str_starts_with($value, '@') ? substr($value, 1) : null;
            $valid = $domain !== null
                ? preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/D', $domain) === 1
                : filter_var($value, FILTER_VALIDATE_EMAIL) !== false && strlen($value) <= 254;

            if (! $valid) {
                $fail(__(':sender is not an email address or a domain.', ['sender' => $value]));
            }
        };

        return [
            'threshold' => ['required', 'integer', 'min:'.MailSpamSetting::MIN_THRESHOLD, 'max:'.MailSpamSetting::MAX_THRESHOLD],
            'action' => ['required', Rule::in(MailSpamSetting::ACTIONS)],
            'whitelist' => ['array', 'max:'.MailSpamSetting::MAX_SENDERS],
            'whitelist.*' => ['string', $sender],
            'blacklist' => ['array', 'max:'.MailSpamSetting::MAX_SENDERS],
            'blacklist.*' => ['string', $sender],
        ];
    }
}
