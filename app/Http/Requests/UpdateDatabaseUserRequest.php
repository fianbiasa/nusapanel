<?php

namespace App\Http\Requests;

use App\Models\DatabaseUser;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateDatabaseUserRequest extends FormRequest
{
    /**
     * Only the account that owns the user; other accounts' users are
     * reported as missing.
     */
    public function authorize(): Response
    {
        $user = $this->route('databaseUser');

        abort_unless($user instanceof DatabaseUser, 404);

        return Gate::inspect('update', $user);
    }

    /**
     * Get the validation rules that apply to the request: a new password
     * (empty keeps the current one) and the databases the user may use.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password' => ['nullable', 'string', 'min:12', 'max:128'],
            'databases' => ['present', 'array', 'max:100'],
            'databases.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['databases' => $this->input('databases', [])]);
    }
}
