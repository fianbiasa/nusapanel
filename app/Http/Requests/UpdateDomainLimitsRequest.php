<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class UpdateDomainLimitsRequest extends WebsiteSettingsRequest
{
    /**
     * Get the validation rules that apply to the request: the bandwidth per
     * calendar month and the disk of the website, in megabytes; empty is no
     * limit.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'bandwidth_limit_mb' => ['nullable', 'integer', 'between:1,1000000000'],
            'disk_limit_mb' => ['nullable', 'integer', 'between:1,1000000000'],
        ];
    }

    /**
     * The limit of field, or null for none.
     */
    public function limit(string $field): ?int
    {
        $value = $this->validated($field);

        return $value === null ? null : (int) $value;
    }
}
