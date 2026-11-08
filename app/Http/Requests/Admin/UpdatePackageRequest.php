<?php

namespace App\Http\Requests\Admin;

use App\Concerns\PackageValidationRules;
use App\Models\Package;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePackageRequest extends FormRequest
{
    use PackageValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Package $package */
        $package = $this->route('package');

        return $this->packageRules($package->id);
    }
}
