<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSftpPasswordRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request: the new SFTP
     * password.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'min:12', 'max:128'],
        ];
    }
}
