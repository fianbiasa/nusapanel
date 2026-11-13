<?php

namespace App\Http\Requests\Settings;

use App\Support\LoginKeyPermissions;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLoginKeyRequest extends FormRequest
{
    public const MAX_ALLOWED_IPS = 20;

    /**
     * Get the validation rules that apply to the request: a name, at least
     * one permission the account's role may give, the addresses the key
     * works from (none is anywhere) and when it stops working.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', 'distinct', Rule::in(LoginKeyPermissions::for($this->user()->role))],
            'allowed_ips' => ['array', 'max:'.self::MAX_ALLOWED_IPS],
            'allowed_ips.*' => ['string', 'distinct', function (string $attribute, mixed $value, Closure $fail): void {
                if (! self::isAddressOrNetwork((string) $value)) {
                    $fail(__(':value is not an IP address or network.', ['value' => (string) $value]));
                }
            }],
            'expires_at' => ['nullable', 'date_format:Y-m-d', 'after:today'],
        ];
    }

    /**
     * The addresses arrive as text, one per line or separated by commas.
     */
    protected function prepareForValidation(): void
    {
        $text = $this->input('allowed_ips');

        $this->merge([
            'allowed_ips' => is_string($text)
                ? array_values(array_filter(array_map(trim(...), preg_split('/[\s,]+/', $text) ?: []), fn (string $value): bool => $value !== ''))
                : ($text ?? []),
        ]);
    }

    /**
     * An IPv4 or IPv6 address, or a network as address/prefix length.
     */
    public static function isAddressOrNetwork(string $value): bool
    {
        [$address, $prefix] = array_pad(explode('/', $value, 2), 2, null);

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if ($prefix === null) {
            return true;
        }

        $max = str_contains((string) $address, ':') ? 128 : 32;

        return ctype_digit($prefix) && (int) $prefix <= $max;
    }
}
