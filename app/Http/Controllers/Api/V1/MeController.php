<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LoginKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    /**
     * The account the login key acts as, and what the key may do.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $account = $request->user();

        /** @var LoginKey $key */
        $key = $request->attributes->get('login_key');

        return response()->json(['data' => [
            'username' => $account->username,
            'name' => $account->name,
            'email' => $account->email,
            'role' => $account->role->value,
            'status' => $account->status->value,
            'login_key' => [
                'name' => $key->name,
                'permissions' => $key->permissions,
                'expires_at' => $key->expires_at?->toIso8601String(),
            ],
        ]]);
    }
}
