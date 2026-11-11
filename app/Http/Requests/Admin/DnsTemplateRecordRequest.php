<?php

namespace App\Http\Requests\Admin;

use App\Services\DnsService;
use App\Support\DnsRecordInput;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class DnsTemplateRecordRequest extends FormRequest
{
    /**
     * A sample zone and address the record is checked with.
     */
    private const SAMPLE = ['{domain}' => 'example.com', '{ip}' => '203.0.113.10'];

    /**
     * Get the validation rules that apply to the request: a record like a
     * customer's, whose name is relative to the zone and whose content may
     * use {domain} and {ip}. It is checked the way a zone of example.com
     * on 203.0.113.10 would take it.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(DnsRecordInput::TYPES)],
            'name' => ['nullable', 'string', 'max:63', 'not_regex:/[{}]/'],
            'content' => ['required', 'string', 'max:'.DnsRecordInput::MAX_TXT],
            'ttl' => ['required', 'integer', 'min:'.DnsRecordInput::MIN_TTL, 'max:'.DnsRecordInput::MAX_TTL],
            'priority' => ['nullable', 'integer'],
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
            'name.not_regex' => __('Placeholders only go in the content.'),
        ];
    }

    /**
     * Check the placeholders, then the record as a sample zone would take it.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $record = $this->record();

                preg_match_all('/\{[^}]*\}/', $record['content'], $matches);

                if (array_diff($matches[0], DnsService::PLACEHOLDERS) !== [] || substr_count($record['content'], '{') !== count($matches[0])) {
                    $validator->errors()->add('content', __('Only {domain} and {ip} can be filled in.'));

                    return;
                }

                $zone = self::SAMPLE['{domain}'];
                $name = DnsRecordInput::absoluteName($record['name'], $zone);
                $content = DnsRecordInput::normalizeContent($record['type'], strtr($record['content'], self::SAMPLE));

                foreach (DnsRecordInput::errors($zone, $name, $record['type'], $content, $record['priority']) as $field => $message) {
                    $validator->errors()->add($field, $message);
                }
            },
        ];
    }

    /**
     * The template record in its stored form: the name relative ("@" for
     * the zone itself), lowercase.
     *
     * @return array{name: string, type: string, content: string, ttl: int, priority: int|null}
     */
    public function record(): array
    {
        $type = $this->string('type')->toString();
        $name = strtolower(trim(trim($this->string('name')->toString()), '.'));

        return [
            'name' => $name === '' ? '@' : $name,
            'type' => $type,
            'content' => DnsRecordInput::normalizeContent($type, $this->string('content')->toString()),
            'ttl' => $this->integer('ttl'),
            'priority' => in_array($type, DnsRecordInput::WITH_PRIORITY, true) && $this->filled('priority') ? $this->integer('priority') : null,
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $type = $this->input('type');

        if (is_string($type)) {
            $this->merge(['type' => strtoupper(trim($type))]);
        }
    }
}
