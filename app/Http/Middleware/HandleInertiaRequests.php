<?php

namespace App\Http\Middleware;

use App\Services\ImpersonationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        // Inertia's own version only looks for public/build/manifest.json;
        // the manifest is in public/assets, so a new release still makes
        // open pages load the new assets.
        return Vite::manifestHash();
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                'impersonator' => fn (): ?array => $this->impersonator($request),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * Who signed in as the current account ("login as"), so every page can
     * say so and offer the way back.
     *
     * @return array{username: string, name: string}|null
     */
    private function impersonator(Request $request): ?array
    {
        $impersonator = app(ImpersonationService::class)->impersonator($request);

        return $impersonator === null ? null : ['username' => $impersonator->username, 'name' => $impersonator->name];
    }
}
