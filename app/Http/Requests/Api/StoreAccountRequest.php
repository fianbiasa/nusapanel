<?php

namespace App\Http\Requests\Api;

use App\Enums\Role;
use App\Http\Requests\Admin\StoreAccountRequest as PanelStoreAccountRequest;

/**
 * A hosting account made through the API: the same checks as in the panel,
 * but only ever a hosting account. A login key never makes an administrator
 * or reseller.
 */
class StoreAccountRequest extends PanelStoreAccountRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        // Confirming a password is for people typing it; a program sends it
        // once.
        $this->merge([
            'role' => Role::User->value,
            'password_confirmation' => $this->input('password_confirmation', $this->input('password')),
        ]);
    }
}
