<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ResellerSetting;
use App\Rules\ValidDomain;
use App\Services\BrandingService;
use App\Services\DnsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * A reseller's branding and nameservers.
 */
class BrandingController extends Controller
{
    public function __construct(
        private BrandingService $branding,
        private DnsService $dns,
    ) {}

    /**
     * Show the reseller's branding and nameservers.
     */
    public function edit(Request $request): Response
    {
        $settings = $this->branding->settings($request->user());

        return Inertia::render('reseller/branding', [
            'branding' => [
                'name' => $settings->brand_name,
                'color' => $settings->brand_color,
                'logo_url' => $this->branding->present($settings)['logo_url'] ?? null,
            ],
            'nameservers' => $settings->nameservers ?? [],
            'panelNameservers' => $this->dns->nameservers(),
            'serverIp' => config('hosting.public_ip'),
        ]);
    }

    /**
     * Change the name, colour and logo; empty values keep the panel's.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'brand_name' => ['nullable', 'string', 'max:40'],
            'brand_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:512', 'dimensions:max_width=2000,max_height=2000'],
            'remove_logo' => ['sometimes', 'boolean'],
        ]);

        $this->branding->updateBranding(
            $request->user(),
            $validated['brand_name'] ?? null,
            $validated['brand_color'] ?? null,
            $request->file('logo'),
            $request->boolean('remove_logo'),
            $request->user(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Branding saved.')]);

        return back();
    }

    /**
     * Set the nameservers of the reseller's accounts' zones: the first,
     * also in every zone's SOA, and an optional second one; none keeps the
     * panel's.
     */
    public function updateNameservers(Request $request): RedirectResponse
    {
        $request->merge([
            'ns1' => ValidDomain::normalize($request->input('ns1')),
            'ns2' => ValidDomain::normalize($request->input('ns2')),
        ]);

        $validated = $request->validate([
            'ns1' => ['nullable', 'required_with:ns2', 'string', new ValidDomain],
            'ns2' => ['nullable', 'string', new ValidDomain, 'different:ns1'],
        ]);

        $nameservers = array_values(array_filter([$validated['ns1'] ?? null, $validated['ns2'] ?? null], is_string(...)));

        $this->branding->updateNameservers($request->user(), $nameservers, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => $nameservers === []
            ? __('Your accounts use the panel\'s nameservers again.')
            : __('Nameservers saved. The zones of your accounts are being updated.')]);

        return back();
    }

    /**
     * Serve a reseller's logo; it is shown before signing in too.
     */
    public function logo(ResellerSetting $resellerSetting): BinaryFileResponse
    {
        $path = $resellerSetting->logo_path;

        abort_if($path === null || ! Storage::disk('local')->exists($path), 404);

        $type = match (pathinfo($path, PATHINFO_EXTENSION)) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => $type,
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
