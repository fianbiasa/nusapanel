<?php

namespace App\Http\Requests\Admin;

use App\Concerns\PackageValidationRules;
use App\Models\Package;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdatePackageRequest extends FormRequest
{
    use PackageValidationRules;

    /**
     * Only the package's manager may change it; others' packages are
     * reported as missing, before their input is even validated.
     */
    public function authorize(): Response
    {
        return Gate::inspect('manage', $this->route('package'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Package $package */
        $package = $this->route('package');

        return $this->packageRules($package->owner ?? $this->user(), $package->id);
    }
}
