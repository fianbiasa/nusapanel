<?php

namespace App\Http\Requests;

use App\Support\SshPublicKey;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreSshKeyRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request: a name for the
     * key and the public key itself, which SshService checks further.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'public_key' => ['required', 'string', 'max:'.SshPublicKey::MAX_LENGTH],
        ];
    }
}
