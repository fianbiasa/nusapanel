<?php

namespace App\Rules;

use App\Support\MailAddress;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Addresses mail is forwarded to end up in Postfix's alias maps, so each
 * one must be a plain address ({@see MailAddress::isValid()}). The value
 * is one address or a list, in the form {@see MailAddress::normalize()}
 * produces.
 */
class ValidMailAddresses implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        foreach ((array) $value as $address) {
            if (! is_string($address) || ! MailAddress::isValid($address)) {
                $fail('":address" is not an email address mail can be forwarded to.')
                    ->translate(['address' => is_string($address) ? $address : '']);

                return;
            }
        }
    }
}
