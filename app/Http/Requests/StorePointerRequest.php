<?php

namespace App\Http\Requests;

use App\Enums\DomainType;
use App\Enums\PointerMode;
use App\Models\Domain;
use App\Rules\ValidDomain;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePointerRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request: one of the
     * account's own domains, the pointer's name and what it does. Whether
     * the name is still free is checked by the DomainService.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'domain_id' => [
                'required',
                'integer',
                Rule::exists(Domain::class, 'id')
                    ->where('user_id', $this->user()?->id)
                    ->where('type', DomainType::Domain->value),
            ],
            'name' => ['required', 'string', new ValidDomain],
            'mode' => ['required', Rule::enum(PointerMode::class)],
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => ValidDomain::normalize($this->input('name')),
        ]);
    }
}
