<?php

namespace App\Http\Requests;

use App\Models\MailDomain;
use App\Rules\ValidMailAddresses;
use App\Support\MailAddress;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateCatchAllRequest extends FormRequest
{
    /**
     * Only the account that owns the domain; other accounts' domains are
     * reported as missing.
     */
    public function authorize(): Response
    {
        $mailDomain = $this->route('mailDomain');

        abort_unless($mailDomain instanceof MailDomain, 404);

        return Gate::inspect('update', $mailDomain);
    }

    /**
     * Get the validation rules that apply to the request: where mail for
     * addresses the domain does not have goes; empty refuses that mail.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'catchall_target' => ['nullable', 'string', 'max:254', new ValidMailAddresses],
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $target = $this->input('catchall_target');

        $this->merge(['catchall_target' => is_string($target) && trim($target) !== '' ? MailAddress::normalize($target) : null]);
    }
}
