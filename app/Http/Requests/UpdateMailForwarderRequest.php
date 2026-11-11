<?php

namespace App\Http\Requests;

use App\Models\MailForwarder;
use App\Rules\ValidMailAddresses;
use App\Support\MailAddress;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateMailForwarderRequest extends FormRequest
{
    /**
     * Only the account that owns the forwarder; other accounts' forwarders
     * are reported as missing.
     */
    public function authorize(): Response
    {
        $forwarder = $this->route('mailForwarder');

        abort_unless($forwarder instanceof MailForwarder, 404);

        return Gate::inspect('update', $forwarder);
    }

    /**
     * Get the validation rules that apply to the request: the addresses
     * mail goes on to (typed one per line or comma separated).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
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
            'destinations.required' => __('Enter at least one address to forward to.'),
            'destinations.max' => __('A forwarder can send to at most :max addresses.'),
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $destinations = $this->input('destinations');

        $this->merge([
            'destinations' => is_string($destinations) ? MailAddress::parseList($destinations) : $destinations,
        ]);
    }
}
