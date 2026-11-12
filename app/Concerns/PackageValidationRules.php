<?php

namespace App\Concerns;

use App\Enums\Role;
use App\Models\Package;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

trait PackageValidationRules
{
    /**
     * Get the validation rules used to validate packages of owner. Every
     * limit must be sent; an empty limit means unlimited. The disk quota
     * limits start at 1, because the server reads a quota of 0 as
     * unlimited. A name is unique among the owner's packages: a reseller's
     * own, or all the administrators' packages.
     *
     * @return array<string, array<int, Unique|ValidationRule|array<mixed>|string>>
     */
    protected function packageRules(User $owner, ?int $packageId = null): array
    {
        $limitRules = ['present', 'nullable', 'integer', 'min:0', 'max:1000000000'];
        $quotaRules = ['present', 'nullable', 'integer', 'min:1', 'max:1000000000'];

        return [
            'name' => [
                'required',
                'string',
                'max:64',
                Rule::unique(Package::class)
                    ->ignore($packageId)
                    ->where(fn (Builder $query) => $owner->isReseller()
                        ? $query->where('owner_id', $owner->id)
                        : $query->where(fn (Builder $query) => $query
                            ->whereNull('owner_id')
                            ->orWhereIn('owner_id', User::query()->select('id')->where('role', Role::Admin)))),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            ...array_fill_keys(Package::LIMITS, $limitRules),
            'disk_mb' => $quotaRules,
            'inodes' => $quotaRules,
            'ssl' => ['required', 'boolean'],
            'cgi' => ['sometimes', 'boolean'],
        ];
    }
}
