<?php

namespace App\Http\Requests;

use App\Rules\CronSchedule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreCronJobRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request: when to run, the
     * one-line command and an optional description.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'schedule' => ['required', 'string', 'max:100', new CronSchedule],
            'command' => ['required', 'string', 'max:1024', 'not_regex:/[\r\n\x00]/'],
            'description' => ['nullable', 'string', 'max:100'],
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
            'command.not_regex' => __('Write the command on one line.'),
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $command = $this->input('command');

        $this->merge([
            'schedule' => CronSchedule::normalize($this->input('schedule')),
            'command' => is_string($command) ? trim($command) : $command,
        ]);
    }
}
