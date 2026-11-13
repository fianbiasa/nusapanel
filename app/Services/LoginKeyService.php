<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Models\LoginKey;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Login keys: what an account uses the API with. A key is "rxk_" and 40
 * random letters and digits; the panel keeps its SHA-256 and shows the key
 * once. Every API request is authenticated by the key it sends as a bearer
 * token (AuthenticateWithLoginKey).
 */
class LoginKeyService
{
    public const PREFIX = 'rxk_';

    public const MAX_PER_ACCOUNT = 20;

    /**
     * How often the last use of a key is written, at most.
     */
    private const LAST_USED_EVERY_SECONDS = 60;

    public function __construct(private AuditLogger $audit) {}

    /**
     * Make a key for the account and return it with the key itself, which is
     * shown once and never again.
     *
     * @param  list<string>  $permissions
     * @param  list<string>  $allowedIps
     * @return array{key: LoginKey, secret: string}
     *
     * @throws ValidationException
     */
    public function create(User $account, string $name, array $permissions, array $allowedIps, ?CarbonInterface $expiresAt, User $actor): array
    {
        $secret = self::PREFIX.Str::random(40);

        $key = DB::transaction(function () use ($account, $name, $permissions, $allowedIps, $expiresAt, $secret, $actor): LoginKey {
            // Locks the account, so two keys made at once both count.
            User::query()->whereKey($account->id)->lockForUpdate()->first();

            if ($account->loginKeys()->count() >= self::MAX_PER_ACCOUNT) {
                throw ValidationException::withMessages(['name' => __('An account may have at most :max login keys.', ['max' => self::MAX_PER_ACCOUNT])]);
            }

            $key = new LoginKey([
                'name' => $name,
                'permissions' => $permissions,
                'allowed_ips' => $allowedIps === [] ? null : $allowedIps,
                'expires_at' => $expiresAt,
            ]);

            $key->forceFill([
                'user_id' => $account->id,
                'key_hash' => hash('sha256', $secret),
                'key_prefix' => substr($secret, 0, 12),
            ])->save();

            $this->audit->record('login_key.created', $key, [
                'name' => $name,
                'permissions' => $key->permissions,
                'allowed_ips' => $key->allowed_ips,
                'expires_at' => $expiresAt?->toIso8601String(),
            ], $actor);

            return $key;
        });

        return ['key' => $key, 'secret' => $secret];
    }

    /**
     * Delete a key; requests with it fail from now on.
     */
    public function delete(LoginKey $key, User $actor): void
    {
        $key->delete();

        $this->audit->record('login_key.deleted', $key, ['name' => $key->name], $actor);
    }

    /**
     * The account a request's bearer token belongs to, or null when it is no key, an unknown one or an expired one. A key used
     * from an address it does not allow, or for an account that is not
     * active, is refused outright.
     */
    public static function authenticate(Request $request): ?User
    {
        $token = (string) $request->bearerToken();

        if (! str_starts_with($token, self::PREFIX) || strlen($token) > 100) {
            return null;
        }

        $key = LoginKey::query()->with('user')->where('key_hash', hash('sha256', $token))->first();

        if ($key === null || $key->isExpired() || $key->user === null) {
            return null;
        }

        if (! $key->allowsAddress($request->ip())) {
            throw new AccessDeniedHttpException(__('This login key may not be used from :ip.', ['ip' => (string) $request->ip()]));
        }

        if ($key->user->status !== AccountStatus::Active) {
            throw new AccessDeniedHttpException(__('This account is suspended or not active yet.'));
        }

        $request->attributes->set('login_key', $key);

        if ($key->last_used_at === null || $key->last_used_at->diffInSeconds(now()) >= self::LAST_USED_EVERY_SECONDS || $key->last_used_ip !== $request->ip()) {
            LoginKey::query()->whereKey($key->id)->toBase()->update(['last_used_at' => now(), 'last_used_ip' => $request->ip()]);
        }

        return $key->user;
    }
}
