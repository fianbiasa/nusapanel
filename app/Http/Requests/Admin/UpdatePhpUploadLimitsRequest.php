<?php

namespace App\Http\Requests\Admin;

use App\Support\PhpSettings;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdatePhpUploadLimitsRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'limit' => ['required', Rule::in(PhpSettings::SIZES)],
            'default' => ['required', Rule::in(PhpSettings::SIZES)],
        ];
    }

    /**
     * Get the "after" validation callables for the request: websites must
     * be allowed the size they get by default.
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

                if (PhpSettings::megabytes($this->string('default')->toString())
                    > PhpSettings::megabytes($this->string('limit')->toString())) {
                    $validator->errors()->add('default', __('The default size may not be larger than the limit.'));
                }
            },
        ];
    }
}
