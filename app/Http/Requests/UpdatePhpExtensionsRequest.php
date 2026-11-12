<?php

namespace App\Http\Requests;

use App\Support\WebSettings;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class UpdatePhpExtensionsRequest extends WebsiteSettingsRequest
{
    /**
     * Get the validation rules that apply to the request: the extensions
     * that also run as PHP. Files the web server sends from disk never
     * can, since it would show their source.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'php_extensions' => ['present', 'array', 'max:'.WebSettings::MAX_PHP_EXTENSIONS],
            'php_extensions.*' => [
                'string',
                'distinct',
                'regex:'.WebSettings::EXTENSION_PATTERN,
                Rule::notIn([...WebSettings::STATIC_EXTENSIONS, ...WebSettings::ALWAYS_PHP]),
            ],
        ];
    }

    /**
     * The extensions to store, sorted.
     *
     * @return list<string>
     */
    public function phpExtensions(): array
    {
        /** @var list<string> $extensions */
        $extensions = array_values((array) $this->validated('php_extensions'));
        sort($extensions);

        return $extensions;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'php_extensions.*.regex' => __('An extension has up to 16 lowercase letters and digits, without the dot.'),
            'php_extensions.*.not_in' => __('.:input files cannot run as PHP: they already do, or the web server sends them as they are.'),
        ];
    }

    /**
     * Prepare the data for validation: ".HTML" means html.
     */
    protected function prepareForValidation(): void
    {
        $extensions = $this->input('php_extensions');

        if (is_array($extensions)) {
            $extensions = array_map(fn (mixed $extension): mixed => is_string($extension) ? strtolower(ltrim(trim($extension), '.')) : $extension, $extensions);
        }

        $this->merge(['php_extensions' => $extensions ?? []]);
    }
}
