<?php

namespace App\Http\Requests\Admin;

use App\Concerns\ResellerPackageValidationRules;
use App\Models\ResellerPackage;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateResellerPackageRequest extends FormRequest
{
    use ResellerPackageValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var ResellerPackage $package */
        $package = $this->route('resellerPackage');

        return $this->resellerPackageRules($package->id);
    }
}
