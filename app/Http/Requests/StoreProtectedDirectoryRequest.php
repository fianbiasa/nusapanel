<?php

namespace App\Http\Requests;

use App\Support\WebSettings;
use Illuminate\Contracts\Validation\ValidationRule;

class StoreProtectedDirectoryRequest extends WebsiteSettingsRequest
{
    /**
     * Get the validation rules that apply to the request: the folder,
     * relative to the document root, and the text of the login prompt.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'path' => ['required', 'string', 'max:255', 'regex:'.WebSettings::PROTECTED_PATH_PATTERN],
            'realm' => ['required', 'string', 'regex:'.WebSettings::REALM_PATTERN],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'path.regex' => __('Enter a folder of the website like /admin: folder names of letters, digits and ._- that do not start with a dot.'),
            'realm.regex' => __('The prompt may have up to 64 letters, digits, spaces and ._,:()!?@&+/-.'),
        ];
    }

    /**
     * Prepare the data for validation: "admin/" means /admin.
     */
    protected function prepareForValidation(): void
    {
        $path = $this->input('path');

        if (is_string($path)) {
            $path = '/'.trim(trim($path), '/');
        }

        $realm = $this->input('realm');

        $this->merge([
            'path' => $path,
            'realm' => is_string($realm) ? trim($realm) : $realm,
        ]);
    }
}
