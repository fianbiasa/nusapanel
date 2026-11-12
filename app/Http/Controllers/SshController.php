<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSshKeyRequest;
use App\Http\Requests\UpdateSshAccessRequest;
use App\Models\SshKey;
use App\Services\SshService;
use App\Support\AccountLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class SshController extends Controller
{
    public function __construct(private SshService $ssh) {}

    /**
     * The SSH page of the signed-in account: its access and its keys.
     */
    public function index(Request $request): Response
    {
        $account = $request->user();

        return Inertia::render('ssh/index', [
            'access' => [
                'allowed' => AccountLimits::for($account)->allowsSsh(),
                'enabled' => $account->ssh_enabled,
                'active' => $this->ssh->isActive($account),
                ...$this->ssh->syncState($account),
            ],
            'connection' => [
                'host' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost',
                'port' => 22,
                'username' => $account->username,
                'home' => '/home/'.$account->username,
                'password_set' => $account->sftp_password_set_at !== null,
            ],
            'keys' => $account->sshKeys()
                ->orderBy('name')
                ->get()
                ->map(fn (SshKey $key): array => [
                    'id' => $key->id,
                    'name' => $key->name,
                    'type' => strtok($key->public_key, ' '),
                    'fingerprint' => $key->fingerprint,
                    'created_at' => $key->created_at?->toIso8601String(),
                ]),
            'max_keys' => SshService::MAX_KEYS,
        ]);
    }

    /**
     * Turn SSH on or off for the signed-in account.
     */
    public function updateAccess(UpdateSshAccessRequest $request): RedirectResponse
    {
        $enabled = $request->boolean('enabled');

        $this->ssh->setEnabled($request->user(), $enabled, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => $enabled
            ? __('SSH is being turned on.')
            : __('SSH is being turned off.')]);

        return back();
    }

    /**
     * Add a public key to the signed-in account.
     */
    public function storeKey(StoreSshKeyRequest $request): RedirectResponse
    {
        $key = $this->ssh->addKey(
            $request->user(),
            $request->string('name')->trim()->toString(),
            $request->string('public_key')->toString(),
            $request->user(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The key :name is being added.', ['name' => $key->name])]);

        return back();
    }

    /**
     * Remove one of the signed-in account's keys.
     */
    public function destroyKey(Request $request, SshKey $sshKey): RedirectResponse
    {
        Gate::authorize('delete', $sshKey);

        $this->ssh->removeKey($sshKey, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The key :name is being removed.', ['name' => $sshKey->name])]);

        return back();
    }
}
