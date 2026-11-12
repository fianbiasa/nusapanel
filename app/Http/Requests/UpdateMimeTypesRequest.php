<?php

namespace App\Http\Requests;

use App\Support\WebSettings;
use Illuminate\Contracts\Validation\ValidationRule;

class UpdateMimeTypesRequest extends WebsiteSettingsRequest
{
    /**
     * Get the validation rules that apply to the request: every content
     * type the website sets, by file extension.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'mime_types' => ['present', 'array', 'max:'.WebSettings::MAX_MIME_TYPES],
            'mime_types.*' => ['array:extension,type'],
            'mime_types.*.extension' => ['required', 'string', 'distinct', 'regex:'.WebSettings::EXTENSION_PATTERN],
            'mime_types.*.type' => ['required', 'string', 'regex:'.WebSettings::MEDIA_TYPE_PATTERN],
        ];
    }

    /**
     * The content types to store, by extension.
     *
     * @return list<array{extension: string, type: string}>
     */
    public function mimeTypes(): array
    {
        /** @var list<array{extension: string, type: string}> $types */
        $types = array_values((array) $this->validated('mime_types'));

        usort($types, fn (array $a, array $b): int => strcmp($a['extension'], $b['extension']));

        return $types;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mime_types.*.extension.regex' => __('An extension has up to 16 lowercase letters and digits, without the dot.'),
            'mime_types.*.extension.distinct' => __('Each extension can have one content type.'),
            'mime_types.*.type.regex' => __('Enter a content type like application/x-example, in lowercase.'),
        ];
    }

    /**
     * Prepare the data for validation: ".FOO" means foo.
     */
    protected function prepareForValidation(): void
    {
        $types = $this->input('mime_types');

        if (is_array($types)) {
            $types = array_map(fn (mixed $type): mixed => is_array($type) ? [
                'extension' => is_string($type['extension'] ?? null) ? strtolower(ltrim(trim($type['extension']), '.')) : ($type['extension'] ?? null),
                'type' => is_string($type['type'] ?? null) ? strtolower(trim($type['type'])) : ($type['type'] ?? null),
            ] : $type, $types);
        }

        $this->merge(['mime_types' => $types ?? []]);
    }
}
