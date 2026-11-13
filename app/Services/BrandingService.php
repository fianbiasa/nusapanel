<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Domain;
use App\Models\ResellerSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * How resellers present the panel to their hosting accounts: a name,
 * colour and logo in place of RAYXO's, a domain of their own to sign in
 * at, and nameservers of their own for their accounts' zones.
 */
class BrandingService
{
    /**
     * Where logos are kept on the local disk, as <reseller id>.<extension>.
     */
    public const LOGO_DIRECTORY = 'branding';

    public function __construct(
        private AuditLogger $audit,
        private DnsService $dns,
        private TaskDispatcher $tasks,
    ) {}

    /**
     * The reseller's settings, new and unsaved while it has none.
     */
    public function settings(User $reseller): ResellerSetting
    {
        return ResellerSetting::query()->where('user_id', $reseller->id)->first()
            ?? (new ResellerSetting)->forceFill(['user_id' => $reseller->id]);
    }

    /**
     * Change the reseller's name, colour and logo; null keeps the panel's.
     * A new logo replaces the old one, which $removeLogo also removes.
     */
    public function updateBranding(User $reseller, ?string $name, ?string $color, ?UploadedFile $logo, bool $removeLogo, User $actor): void
    {
        $settings = $this->settings($reseller);
        $settings->fill(['brand_name' => $name, 'brand_color' => $color === null ? null : strtolower($color)]);

        $previousLogo = $settings->logo_path;

        if ($logo !== null) {
            $path = $logo->storeAs(self::LOGO_DIRECTORY, $reseller->id.'.'.$logo->extension(), 'local');
            $settings->forceFill(['logo_path' => $path === false ? null : $path]);
        } elseif ($removeLogo) {
            $settings->forceFill(['logo_path' => null]);
        }

        $settings->save();
        $reseller->setRelation('resellerSetting', $settings);

        if ($previousLogo !== null && $previousLogo !== $settings->logo_path) {
            Storage::disk('local')->delete($previousLogo);
        }

        $this->audit->record('reseller.branding_updated', $reseller, [
            'name' => $settings->brand_name,
            'color' => $settings->brand_color,
            'logo' => $settings->logo_path !== null,
        ], $actor);
    }

    /**
     * Set the nameservers the reseller's accounts' zones name, or the
     * panel's again (an empty list), and send those zones again.
     *
     * @param  list<string>  $nameservers  Validated host names.
     */
    public function updateNameservers(User $reseller, array $nameservers, User $actor): void
    {
        DB::transaction(function () use ($reseller, $nameservers, $actor): void {
            $settings = $this->settings($reseller);
            $previous = $settings->nameservers;
            $settings->fill(['nameservers' => $nameservers === [] ? null : $nameservers])->save();
            $reseller->setRelation('resellerSetting', $settings);

            if ($previous === $settings->nameservers) {
                return;
            }

            $this->audit->record('reseller.nameservers_updated', $reseller, [
                'from' => $previous,
                'to' => $settings->nameservers,
            ], $actor);

            $this->dns->syncResellerAccounts($reseller, $actor);
        });
    }

    /**
     * Have the panel answer at a domain of the reseller's, with its own
     * Let's Encrypt certificate, or no longer (null). The domain must not
     * be a website's or another reseller's; the agent also refuses the
     * server's own names. A previous domain is taken down.
     *
     * @param  string|null  $domain  A validated, normalized domain.
     *
     * @throws ValidationException
     */
    public function setLoginDomain(User $reseller, ?string $domain, User $actor): void
    {
        if ($domain !== null) {
            if (Domain::query()->where('name', $domain)->exists()) {
                throw ValidationException::withMessages(['login_domain' => __('This domain is a website on this server.')]);
            }

            if (ResellerSetting::query()->where('login_domain', $domain)->where('user_id', '!=', $reseller->id)->exists()) {
                throw ValidationException::withMessages(['login_domain' => __('Another reseller signs in at this domain.')]);
            }
        }

        DB::transaction(function () use ($reseller, $domain, $actor): void {
            $settings = $this->settings($reseller);
            $previous = $settings->login_domain;

            if ($previous === $domain && $settings->login_domain_status !== 'failed') {
                return;
            }

            $settings->forceFill([
                'login_domain' => $domain,
                'login_domain_status' => $domain === null ? null : 'pending',
                'login_domain_error' => null,
            ])->save();
            $reseller->setRelation('resellerSetting', $settings);

            if ($previous !== null && $previous !== $domain) {
                $this->tasks->dispatch('panel.remove_login_domain', ['domain' => $previous], $reseller, $actor);
            }

            if ($domain !== null) {
                $this->tasks->dispatch('panel.add_login_domain', ['domain' => $domain], $reseller, $actor);
            }

            $this->audit->record('reseller.login_domain_changed', $reseller, ['from' => $previous, 'to' => $domain], $actor);
        });
    }

    /**
     * The branding the request is shown: a reseller's own, or for a
     * hosting account its reseller's; null for the panel's own.
     *
     * @return array{name: string|null, color: string|null, logo_url: string|null}|null
     */
    public function forRequest(Request $request): ?array
    {
        $user = $request->user();

        if ($user === null) {
            // Before signing in, a reseller's own sign-in domain shows its brand.
            return $this->present(ResellerSetting::query()
                ->where('login_domain', strtolower($request->getHost()))
                ->where('login_domain_status', 'active')
                ->first());
        }

        $reseller = match (true) {
            $user->isReseller() => $user,
            $user->hasRole(Role::User) && $user->owner?->isReseller() === true => $user->owner,
            default => null,
        };

        return $reseller === null ? null : $this->present($reseller->resellerSetting);
    }

    /**
     * The branding of the settings, or null when they keep the panel's.
     *
     * @return array{name: string|null, color: string|null, logo_url: string|null}|null
     */
    public function present(?ResellerSetting $settings): ?array
    {
        if ($settings === null || ($settings->brand_name === null && $settings->brand_color === null && $settings->logo_path === null)) {
            return null;
        }

        return [
            'name' => $settings->brand_name,
            'color' => $settings->brand_color,
            'logo_url' => $settings->logo_path === null
                ? null
                : route('branding.logo', ['resellerSetting' => $settings->id, 'v' => $settings->updated_at?->getTimestamp()]),
        ];
    }
}
