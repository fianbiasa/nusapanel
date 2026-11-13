<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\ResellerSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * How resellers present the panel to their hosting accounts: a name,
 * colour and logo in place of RAYXO's, and nameservers of their own for
 * their accounts' zones.
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
     * The branding the request is shown: a reseller's own, or for a
     * hosting account its reseller's; null for the panel's own.
     *
     * @return array{name: string|null, color: string|null, logo_url: string|null}|null
     */
    public function forRequest(Request $request): ?array
    {
        $user = $request->user();

        if ($user === null) {
            return null;
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
