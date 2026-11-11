<?php

namespace App\Http\Requests;

use App\Services\CronJobService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCronEmailRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request: the address the
     * output of the account's cron jobs is mailed to, empty for none. It is
     * written into the crontab and handed to sendmail, so only plain
     * addresses are accepted, as the agent checks too.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'cron_email' => ['nullable', 'string', 'max:'.CronJobService::MAX_MAIL_TO, 'regex:'.CronJobService::MAIL_TO_PATTERN],
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
            'cron_email.regex' => __('Use a plain email address, such as you@example.com.'),
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $email = $this->input('cron_email');

        if (is_string($email)) {
            $this->merge(['cron_email' => trim($email) === '' ? null : trim($email)]);
        }
    }
}
