<?php

namespace App\Http\Requests;

use App\Models\Domain;
use App\Rules\ValidRedirectDestination;
use App\Rules\ValidRedirectPath;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUrlRedirectRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request: one of the
     * account's own websites, the path on it and where it goes. Whether the
     * website can take the redirect is checked by the UrlRedirectService.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'domain_id' => [
                'required',
                'integer',
                Rule::exists(Domain::class, 'id')->where('user_id', $this->user()?->id),
            ],
            'path' => ['required', 'string', new ValidRedirectPath],
            'destination' => ['required', 'string', new ValidRedirectDestination($this->boolean('wildcard'))],
            'status_code' => ['required', 'integer', Rule::in([301, 302])],
            'wildcard' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'path' => ValidRedirectPath::normalize($this->input('path')),
            'destination' => ValidRedirectDestination::normalize($this->input('destination')),
        ]);
    }
}
