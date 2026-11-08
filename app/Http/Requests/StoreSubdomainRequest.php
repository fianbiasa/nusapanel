<?php

namespace App\Http\Requests;

use App\Enums\DomainType;
use App\Models\Domain;
use App\Rules\ValidDomain;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSubdomainRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request: one of the
     * account's own domains, and one DNS label for the subdomain. Whether
     * the full name is still free is checked by the DomainService.
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
            'name' => ['required', 'string', 'max:63', 'regex:'.ValidDomain::LABEL_PATTERN, Rule::notIn(['www'])],
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $parent = Domain::query()->find($this->integer('domain_id'));

                if ($parent !== null && strlen($this->string('name').'.'.$parent->name) > 253) {
                    $validator->errors()->add('name', __('The subdomain makes the full name longer than 253 characters.'));
                }
            },
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
            'name.regex' => __('Enter only the name of the subdomain, such as "blog", with letters, digits and hyphens.'),
            'name.not_in' => __('www is served automatically with every domain.'),
        ];
    }

    /**
     * Prepare the data for validation: lowercase, in punycode when it
     * contains non-ASCII letters.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => ValidDomain::normalize($this->input('name')),
        ]);
    }
}
