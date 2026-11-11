<?php

namespace App\Http\Requests\Admin;

use App\Enums\BulkAccountAction;
use App\Models\Package;
use App\Services\AccountService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkAccountActionRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request: what to do, to
     * which accounts, with the suspension's optional reason or the package
     * to move to (empty takes the accounts off packages). Whether each
     * account may get it is checked per account by the AccountService.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::enum(BulkAccountAction::class)],
            'ids' => ['required', 'array', 'min:1', 'max:'.AccountService::MAX_BULK],
            'ids.*' => ['integer', 'distinct'],
            'reason' => ['exclude_unless:action,suspend', 'nullable', 'string', 'max:255'],
            'package_id' => ['exclude_unless:action,package', 'present', 'nullable', 'integer', Rule::exists(Package::class, 'id')],
        ];
    }
}
