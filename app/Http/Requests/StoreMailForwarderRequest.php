<?php

namespace App\Http\Requests;

use App\Rules\ValidMailAddresses;
use App\Support\MailAddress;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreMailForwarderRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request: the name before
     * "@<domain>" (as for mailboxes), the domain, and the addresses mail
     * goes on to (typed one per line or comma separated). Whether the
     * domain is the account's is checked by the MailForwarderService.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'local_part' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_+-]+(\.[a-z0-9_+-]+)*$/D'],
            'domain_id' => ['required', 'integer'],
            'destinations' => ['required', 'array', 'min:1', 'max:'.MailAddress::MAX_DESTINATIONS, new ValidMailAddresses],
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
            'destinations.required' => __('Enter at least one address to forward to.'),
            'destinations.max' => __('A forwarder can send to at most :max addresses.'),
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $localPart = $this->input('local_part');
        $destinations = $this->input('destinations');

        $this->merge([
            'local_part' => is_string($localPart) ? strtolower(trim($localPart)) : $localPart,
            'destinations' => is_string($destinations) ? MailAddress::parseList($destinations) : $destinations,
        ]);
    }
}
