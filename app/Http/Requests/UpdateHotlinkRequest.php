<?php

namespace App\Http\Requests;

use App\Rules\ValidDomain;
use App\Rules\ValidRedirectDestination;
use App\Support\WebSettings;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class UpdateHotlinkRequest extends WebsiteSettingsRequest
{
    /**
     * Get the validation rules that apply to the request: whether hotlink
     * protection is on, the files it covers, the other websites that may
     * embed them, whether requests without a referrer are served, and an
     * optional address refused requests go to instead.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $enabled = $this->boolean('enabled');

        return [
            'enabled' => ['required', 'boolean'],
            'extensions' => [Rule::requiredIf($enabled), 'array', 'max:'.count(WebSettings::STATIC_EXTENSIONS)],
            'extensions.*' => ['string', 'distinct', Rule::in(WebSettings::STATIC_EXTENSIONS)],
            'domains' => ['array', 'max:'.WebSettings::MAX_HOTLINK_DOMAINS],
            'domains.*' => ['string', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! ValidDomain::isWellFormed($value)) {
                    $fail(__(':value is not a valid domain name.', ['value' => is_string($value) ? $value : '']));
                }
            }],
            'allow_direct' => ['required', 'boolean'],
            'redirect_to' => ['nullable', 'string', new ValidRedirectDestination, function (string $attribute, mixed $value, Closure $fail): void {
                $host = is_string($value) ? strtolower((string) parse_url($value, PHP_URL_HOST)) : '';
                $domain = $this->website()->name;

                if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                    $fail(__('Send refused requests to another website: this one would refuse them again.'));
                }
            }],
        ];
    }

    /**
     * The settings to store, or null when hotlink protection is off.
     *
     * @return array{extensions: list<string>, domains: list<string>, allow_direct: bool, redirect_to: string|null}|null
     */
    public function hotlink(): ?array
    {
        if (! $this->boolean('enabled')) {
            return null;
        }

        /** @var list<string> $extensions */
        $extensions = array_values(array_intersect(WebSettings::STATIC_EXTENSIONS, (array) $this->validated('extensions')));
        /** @var list<string> $domains */
        $domains = array_values((array) $this->validated('domains', []));
        $redirect = $this->validated('redirect_to');

        return [
            'extensions' => $extensions,
            'domains' => $domains,
            'allow_direct' => $this->boolean('allow_direct'),
            'redirect_to' => is_string($redirect) && $redirect !== '' ? $redirect : null,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'extensions.required' => __('Choose at least one kind of file to protect.'),
        ];
    }

    /**
     * Prepare the data for validation: the other websites come as text, one
     * or more per line.
     */
    protected function prepareForValidation(): void
    {
        $domains = $this->input('domains');

        if (is_string($domains)) {
            $domains = preg_split('/[\s,]+/', $domains, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        if (is_array($domains)) {
            $domains = array_values(array_unique(array_map(ValidDomain::normalize(...), $domains), SORT_REGULAR));
        }

        $this->merge([
            'domains' => $domains ?? [],
            'redirect_to' => ValidRedirectDestination::normalize($this->input('redirect_to')),
        ]);
    }
}
