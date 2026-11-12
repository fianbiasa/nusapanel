<?php

namespace App\Http\Controllers;

use App\Enums\DomainType;
use App\Enums\PointerMode;
use App\Http\Requests\SaveErrorPageRequest;
use App\Http\Requests\StoreProtectedDirectoryRequest;
use App\Http\Requests\StoreProtectedLoginRequest;
use App\Http\Requests\UpdateCgiRequest;
use App\Http\Requests\UpdateDomainLimitsRequest;
use App\Http\Requests\UpdateHotlinkRequest;
use App\Http\Requests\UpdateMimeTypesRequest;
use App\Http\Requests\UpdatePhpExtensionsRequest;
use App\Http\Requests\UpdateProtectedLoginRequest;
use App\Models\Domain;
use App\Models\ProtectedDirectory;
use App\Models\ProtectedDirectoryUser;
use App\Services\BandwidthService;
use App\Services\DomainLimitService;
use App\Services\WebSettingsService;
use App\Support\AccountLimits;
use App\Support\WebSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The web settings page of a domain or subdomain: its own error pages,
 * protected folders, hotlink protection, content types and PHP extensions.
 * A pointer has none of its own, so it has no such page.
 */
class DomainWebSettingsController extends Controller
{
    public function __construct(
        private WebSettingsService $settings,
        private DomainLimitService $limits,
    ) {}

    /**
     * Show the web settings of the website. The content of its error pages
     * is read from its files after the page has loaded.
     */
    public function show(Domain $domain): Response
    {
        Gate::authorize('update', $domain);

        abort_unless($domain->hasOwnFolder(), 404);

        $domain->load([
            'protectedDirectories' => fn ($query) => $query->orderBy('path'),
            'protectedDirectories.logins' => fn ($query) => $query->orderBy('username'),
            'children' => fn ($query) => $query->where('type', DomainType::Pointer)->where('pointer_mode', PointerMode::Alias)->orderBy('name'),
        ]);

        return Inertia::render('domains/web', [
            'domain' => [
                'id' => $domain->id,
                'name' => $domain->name,
                'display_name' => $domain->display_name,
                'status' => $domain->status->value,
                'php_enabled' => $domain->php_enabled,
                'document_root' => $domain->documentRoot(),
                'aliases' => $domain->children->map(fn (Domain $alias): string => $alias->display_name)->values(),
            ],
            'error_pages' => $domain->error_pages ?? [],
            'error_page_contents' => Inertia::defer(fn (): array => $this->settings->errorPageContents($domain)),
            'protected_directories' => $domain->protectedDirectories->map(fn (ProtectedDirectory $directory): array => [
                'id' => $directory->id,
                'path' => $directory->path,
                'realm' => $directory->realm,
                'logins' => $directory->logins->map(fn (ProtectedDirectoryUser $login): array => [
                    'id' => $login->id,
                    'username' => $login->username,
                ])->values(),
            ])->values(),
            'hotlink' => $domain->hotlink,
            'mime_types' => $domain->mime_types ?? [],
            'php_extensions' => $domain->php_extensions ?? [],
            'limits' => [
                'bandwidth_limit_mb' => $domain->bandwidth_limit_mb,
                'disk_limit_mb' => $domain->disk_limit_mb,
                'bandwidth_used_mb' => intdiv($this->limits->monthBytes($domain), BandwidthService::BYTES_PER_MB),
                'disk_used_mb' => $domain->disk_used_mb,
                'disk_measured_at' => $domain->disk_measured_at?->toIso8601String(),
                'reached' => $domain->limit_reached,
            ],
            'cgi' => [
                'enabled' => $domain->cgi_enabled,
                'allowed' => AccountLimits::for($domain->account)->allowsCgi(),
            ],
            'options' => [
                'error_page_statuses' => WebSettings::ERROR_PAGE_STATUSES,
                'static_extensions' => WebSettings::STATIC_EXTENSIONS,
                'default_hotlink_extensions' => WebSettings::DEFAULT_HOTLINK_EXTENSIONS,
                'max_protected_directories' => WebSettings::MAX_PROTECTED_DIRECTORIES,
                'max_protected_logins' => WebSettings::MAX_PROTECTED_USERS,
                'max_mime_types' => WebSettings::MAX_MIME_TYPES,
                'max_php_extensions' => WebSettings::MAX_PHP_EXTENSIONS,
            ],
        ]);
    }

    /**
     * Write the page the website shows for an error, and turn it on.
     */
    public function saveErrorPage(SaveErrorPageRequest $request, Domain $domain, int $status): RedirectResponse
    {
        $this->settings->saveErrorPage($domain, $status, $request->string('content')->value(), $request->user());

        return $this->applied(__('The :status page of :domain is being applied.', ['status' => $status, 'domain' => $domain->display_name]));
    }

    /**
     * Show the web server's own page for an error again.
     */
    public function removeErrorPage(Request $request, Domain $domain, int $status): RedirectResponse
    {
        Gate::authorize('update', $domain);

        $this->settings->removeErrorPage($domain, $status, $request->user());

        return $this->applied(__('The web server shows its own :status page of :domain again.', ['status' => $status, 'domain' => $domain->display_name]));
    }

    /**
     * Ask for a login before a folder of the website is served.
     */
    public function protectDirectory(StoreProtectedDirectoryRequest $request, Domain $domain): RedirectResponse
    {
        $path = $request->string('path')->value();

        $this->settings->protectDirectory($domain, $path, $request->string('realm')->value(), $request->user());

        return $this->applied(__(':path is protected. Add a login to open it.', ['path' => $path]));
    }

    /**
     * Open a protected folder to everyone again.
     */
    public function unprotectDirectory(Request $request, Domain $domain, ProtectedDirectory $protectedDirectory): RedirectResponse
    {
        Gate::authorize('update', $domain);

        $this->settings->unprotectDirectory($protectedDirectory, $request->user());

        return $this->applied(__(':path is open to everyone again.', ['path' => $protectedDirectory->path]));
    }

    /**
     * Add a login to a protected folder.
     */
    public function addLogin(StoreProtectedLoginRequest $request, Domain $domain, ProtectedDirectory $protectedDirectory): RedirectResponse
    {
        $username = $request->string('username')->value();

        $this->settings->addLogin($protectedDirectory, $username, $request->string('password')->value(), $request->user());

        return $this->applied(__('The login :login can open :path once the website is applied.', [
            'login' => $username,
            'path' => $protectedDirectory->path,
        ]));
    }

    /**
     * Give a login of a protected folder a new password.
     */
    public function changeLoginPassword(UpdateProtectedLoginRequest $request, Domain $domain, ProtectedDirectory $protectedDirectory, ProtectedDirectoryUser $login): RedirectResponse
    {
        $this->settings->changeLoginPassword($login, $request->string('password')->value(), $request->user());

        return $this->applied(__('The new password of :login is being applied.', ['login' => $login->username]));
    }

    /**
     * Remove a login of a protected folder.
     */
    public function removeLogin(Request $request, Domain $domain, ProtectedDirectory $protectedDirectory, ProtectedDirectoryUser $login): RedirectResponse
    {
        Gate::authorize('update', $domain);

        $this->settings->removeLogin($login, $request->user());

        return $this->applied(__('The login :login was removed.', ['login' => $login->username]));
    }

    /**
     * Turn hotlink protection on, change it or turn it off.
     */
    public function updateHotlink(UpdateHotlinkRequest $request, Domain $domain): RedirectResponse
    {
        $this->settings->updateHotlink($domain, $request->hotlink(), $request->user());

        return $this->applied(__('The hotlink protection of :domain is being applied.', ['domain' => $domain->display_name]));
    }

    /**
     * Replace the content types the website sets itself.
     */
    public function updateMimeTypes(UpdateMimeTypesRequest $request, Domain $domain): RedirectResponse
    {
        $this->settings->updateMimeTypes($domain, $request->mimeTypes(), $request->user());

        return $this->applied(__('The content types of :domain are being applied.', ['domain' => $domain->display_name]));
    }

    /**
     * Replace the extensions that also run as PHP.
     */
    public function updatePhpExtensions(UpdatePhpExtensionsRequest $request, Domain $domain): RedirectResponse
    {
        $this->settings->updatePhpExtensions($domain, $request->phpExtensions(), $request->user());

        return $this->applied(__('The PHP extensions of :domain are being applied.', ['domain' => $domain->display_name]));
    }

    /**
     * Run the scripts in the website's cgi-bin, or stop.
     */
    public function updateCgi(UpdateCgiRequest $request, Domain $domain): RedirectResponse
    {
        $enabled = $request->boolean('cgi_enabled');

        $this->settings->setCgi($domain, $enabled, $request->user());

        return $this->applied($enabled
            ? __('CGI is being turned on for :domain.', ['domain' => $domain->display_name])
            : __('CGI is being turned off for :domain.', ['domain' => $domain->display_name]));
    }

    /**
     * Set the bandwidth and disk limits of the website.
     */
    public function updateLimits(UpdateDomainLimitsRequest $request, Domain $domain): RedirectResponse
    {
        $this->limits->setLimits($domain, $request->limit('bandwidth_limit_mb'), $request->limit('disk_limit_mb'), $request->user());

        return $this->applied(__('The limits of :domain were saved.', ['domain' => $domain->display_name]));
    }

    /**
     * Back to the page, with a toast saying what happens next.
     */
    private function applied(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
