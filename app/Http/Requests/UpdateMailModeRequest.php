<?php

namespace App\Http\Requests;

use App\Enums\MailDomainMode;
use App\Enums\MailProvider;
use App\Models\Domain;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateMailModeRequest extends FormRequest
{
    /**
     * Only the account that owns the domain; other accounts' domains are
     * reported as missing.
     */
    public function authorize(): Response
    {
        $domain = $this->route('domain');

        abort_unless($domain instanceof Domain, 404);

        return Gate::inspect('update', $domain);
    }

    /**
     * Get the validation rules that apply to the request: who takes the
     * domain's mail, and for another service, which one.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::enum(MailDomainMode::class)],
            'provider' => ['nullable', 'required_if:mode,external', Rule::enum(MailProvider::class)],
        ];
    }
}
