<?php

namespace App\Http\Requests;

use App\Support\WebSettings;
use Illuminate\Contracts\Validation\ValidationRule;

class SaveErrorPageRequest extends WebsiteSettingsRequest
{
    /**
     * Get the validation rules that apply to the request: the HTML of the
     * page.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'max:'.WebSettings::MAX_ERROR_PAGE_BYTES],
        ];
    }
}
