<?php

namespace App\Http\Requests;

use App\Models\FtpAccount;
use App\Services\FtpService;
use App\Support\FtpDirectory;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateFtpAccountRequest extends FormRequest
{
    /**
     * Only the account that owns the login; other accounts' logins are
     * reported as missing.
     */
    public function authorize(): Response
    {
        $ftpAccount = $this->route('ftpAccount');

        abort_unless($ftpAccount instanceof FtpAccount, 404);

        return Gate::inspect('update', $ftpAccount);
    }

    /**
     * Get the validation rules that apply to the request: a new password
     * (empty keeps the current one), the folder and the FTP quota in
     * megabytes (empty is no limit).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password' => ['nullable', 'string', 'min:12', 'max:128'],
            'directory' => ['present', ...FtpDirectory::rules()],
            'quota_mb' => ['nullable', 'integer', 'min:1', 'max:'.FtpService::MAX_QUOTA_MB],
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['directory' => FtpDirectory::normalize($this->input('directory'))]);
    }
}
