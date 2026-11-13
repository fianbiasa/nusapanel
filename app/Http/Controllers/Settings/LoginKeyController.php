<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreLoginKeyRequest;
use App\Models\LoginKey;
use App\Services\LoginKeyService;
use App\Support\LoginKeyPermissions;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The signed-in account's login keys, which the API accepts instead of a
 * sign-in.
 */
class LoginKeyController extends Controller
{
    public function __construct(private LoginKeyService $keys) {}

    public function index(Request $request): Response
    {
        $account = $request->user();

        return Inertia::render('settings/login-keys', [
            'keys' => $account->loginKeys()->latest('id')->get()->map(fn (LoginKey $key): array => [
                'id' => $key->id,
                'name' => $key->name,
                'prefix' => $key->key_prefix,
                'permissions' => $key->permissions,
                'allowed_ips' => $key->allowed_ips ?? [],
                'expires_at' => $key->expires_at?->toIso8601String(),
                'expired' => $key->isExpired(),
                'last_used_at' => $key->last_used_at?->toIso8601String(),
                'last_used_ip' => $key->last_used_ip,
                'created_at' => $key->created_at?->toIso8601String(),
            ]),
            'permissions' => LoginKeyPermissions::for($account->role),
            'max_keys' => LoginKeyService::MAX_PER_ACCOUNT,
            'api_url' => url('/api/v1'),
        ]);
    }

    /**
     * Make a key and show it once.
     */
    public function store(StoreLoginKeyRequest $request): RedirectResponse
    {
        $expiresAt = $request->filled('expires_at')
            ? CarbonImmutable::createFromFormat('Y-m-d', $request->string('expires_at')->toString())?->endOfDay()
            : null;

        ['key' => $key, 'secret' => $secret] = $this->keys->create(
            $request->user(),
            $request->string('name')->trim()->toString(),
            array_values($request->array('permissions')),
            array_values($request->array('allowed_ips')),
            $expiresAt,
            $request->user(),
        );

        Inertia::flash('login_key', ['name' => $key->name, 'secret' => $secret]);

        return back();
    }

    public function destroy(Request $request, LoginKey $loginKey): RedirectResponse
    {
        Gate::authorize('delete', $loginKey);

        $this->keys->delete($loginKey, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Login key :name is deleted; requests with it fail from now on.', ['name' => $loginKey->name])]);

        return back();
    }
}
