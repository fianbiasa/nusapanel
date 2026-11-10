<?php

namespace App\Http\Requests;

use App\Models\Domain;
use App\Services\ServerSettingsService;
use App\Support\PhpSettings;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdatePhpSettingsRequest extends FormRequest
{
    /**
     * Only the account that owns the website; other accounts' websites are
     * reported as missing.
     */
    public function authorize(): Response
    {
        $domain = $this->route('domain');

        abort_unless($domain instanceof Domain, 404);

        return Gate::inspect('update', $domain);
    }

    /**
     * Get the validation rules that apply to the request. The time limits
     * stay within the 300 seconds a request may take at all.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $sizes = PhpSettings::sizesUpTo(app(ServerSettingsService::class)->phpUploadLimit());

        return [
            'memory_limit' => ['required', Rule::in(PhpSettings::MEMORY_LIMITS)],
            'upload_max_filesize' => ['required', Rule::in($sizes)],
            'post_max_size' => ['required', Rule::in($sizes)],
            'max_execution_time' => ['required', 'integer', 'between:10,300'],
            'max_input_time' => ['required', 'integer', 'between:10,300'],
            'max_input_vars' => ['required', 'integer', 'between:1000,100000'],
            'display_errors' => ['required', 'boolean'],
            'date_timezone' => ['nullable', 'string', 'timezone:all'],
        ];
    }

    /**
     * Get the "after" validation callables for the request: a POST must be
     * able to hold the largest upload.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if (PhpSettings::megabytes($this->string('post_max_size')->toString())
                    < PhpSettings::megabytes($this->string('upload_max_filesize')->toString())) {
                    $validator->errors()->add('post_max_size', __('The POST limit must be at least the upload limit.'));
                }
            },
        ];
    }

    /**
     * The validated settings in their php.ini form, without the server's defaults.
     *
     * @return array<string, string>
     */
    public function settings(): array
    {
        return PhpSettings::fromFields([
            'memory_limit' => $this->string('memory_limit')->toString(),
            'upload_max_filesize' => $this->string('upload_max_filesize')->toString(),
            'post_max_size' => $this->string('post_max_size')->toString(),
            'max_execution_time' => $this->integer('max_execution_time'),
            'max_input_time' => $this->integer('max_input_time'),
            'max_input_vars' => $this->integer('max_input_vars'),
            'display_errors' => $this->boolean('display_errors'),
            'date_timezone' => $this->filled('date_timezone') ? $this->string('date_timezone')->toString() : null,
        ], app(ServerSettingsService::class)->phpDefaults());
    }
}
