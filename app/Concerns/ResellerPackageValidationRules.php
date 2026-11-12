<?php

namespace App\Concerns;

use App\Models\ResellerPackage;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

trait ResellerPackageValidationRules
{
    /**
     * Get the validation rules used to validate reseller packages. Every
     * limit must be sent; an empty limit means unlimited. A reseller gets
     * at least one account, and the disk quota limits start at 1, as for
     * hosting packages.
     *
     * @return array<string, array<int, Unique|ValidationRule|array<mixed>|string>>
     */
    protected function resellerPackageRules(?int $packageId = null): array
    {
        $limitRules = ['present', 'nullable', 'integer', 'min:0', 'max:1000000000'];
        $atLeastOne = ['present', 'nullable', 'integer', 'min:1', 'max:1000000000'];

        return [
            'name' => ['required', 'string', 'max:64', Rule::unique(ResellerPackage::class)->ignore($packageId)],
            'description' => ['nullable', 'string', 'max:255'],
            ...array_fill_keys(ResellerPackage::LIMITS, $limitRules),
            'accounts' => $atLeastOne,
            'disk_mb' => $atLeastOne,
            'inodes' => $atLeastOne,
            'ssl' => ['required', 'boolean'],
            'oversell' => ['required', 'boolean'],
        ];
    }
}
