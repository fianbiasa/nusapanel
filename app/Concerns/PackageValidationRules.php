<?php

namespace App\Concerns;

use App\Models\Package;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

trait PackageValidationRules
{
    /**
     * Get the validation rules used to validate packages. Every limit must
     * be sent; an empty limit means unlimited. The disk quota limits start
     * at 1, because the server reads a quota of 0 as unlimited.
     *
     * @return array<string, array<int, Unique|ValidationRule|array<mixed>|string>>
     */
    protected function packageRules(?int $packageId = null): array
    {
        $limitRules = ['present', 'nullable', 'integer', 'min:0', 'max:1000000000'];
        $quotaRules = ['present', 'nullable', 'integer', 'min:1', 'max:1000000000'];

        return [
            'name' => [
                'required',
                'string',
                'max:64',
                $packageId === null
                    ? Rule::unique(Package::class)
                    : Rule::unique(Package::class)->ignore($packageId),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            ...array_fill_keys(Package::LIMITS, $limitRules),
            'disk_mb' => $quotaRules,
            'inodes' => $quotaRules,
            'ssl' => ['required', 'boolean'],
        ];
    }
}
