<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DeleteQueuedMailRequest extends FormRequest
{
    /**
     * The pattern of a Postfix queue ID, short or long.
     */
    public const QUEUE_ID = '/^[0-9A-Za-z]{5,32}$/D';

    /**
     * Get the validation rules that apply to the request: the queue IDs of
     * the messages to delete, at most 500 at a time.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['required', 'string', 'distinct', 'regex:'.self::QUEUE_ID],
        ];
    }
}
