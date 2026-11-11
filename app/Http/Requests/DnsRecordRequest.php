<?php

namespace App\Http\Requests;

use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Support\DnsRecordInput;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class DnsRecordRequest extends FormRequest
{
    /**
     * Only the account that owns the zone; other accounts' zones and
     * records are reported as missing.
     */
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->zone());
    }

    /**
     * Get the validation rules that apply to the request: the type, the
     * name relative to the zone ("@" or empty for the zone itself), the
     * content, the TTL and, for MX and SRV, the priority. What each type's
     * content may be is checked by {@see DnsRecordInput} afterwards.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(DnsRecordInput::TYPES)],
            'name' => ['nullable', 'string', 'max:253'],
            'content' => ['required', 'string', 'max:'.DnsRecordInput::MAX_TXT],
            'ttl' => ['required', 'integer', 'min:'.DnsRecordInput::MIN_TTL, 'max:'.DnsRecordInput::MAX_TTL],
            'priority' => ['nullable', 'integer'],
        ];
    }

    /**
     * Check the record against its type once the fields themselves are
     * valid.
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

                foreach (DnsRecordInput::errors($this->zone()->name, $record['name'], $record['type'], $record['content'], $record['priority']) as $field => $message) {
                    $validator->errors()->add($field, $message);
                }
            },
        ];
    }

    /**
     * The zone the record is (or goes) in.
     */
    public function zone(): DnsZone
    {
        $zone = $this->route('dnsZone');

        if ($zone instanceof DnsZone) {
            return $zone;
        }

        $record = $this->route('dnsRecord');

        abort_unless($record instanceof DnsRecord, 404);

        return $record->zone;
    }

    /**
     * The record in its stored form.
     *
     * @return array{name: string, type: string, content: string, ttl: int, priority: int|null}
     */
    public function record(): array
    {
        $type = $this->string('type')->toString();

        return [
            'name' => DnsRecordInput::absoluteName($this->string('name')->toString(), $this->zone()->name),
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
