<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFtpAccountRequest;
use App\Http\Requests\UpdateFtpAccountRequest;
use App\Models\Domain;
use App\Models\FtpAccount;
use App\Services\FtpService;
use App\Services\SftpService;
use App\Support\AccountLimits;
use App\Support\FtpDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class FtpAccountController extends Controller
{
    public function __construct(
        private FtpService $ftp,
        private SftpService $sftp,
    ) {}

    /**
     * List the signed-in account's FTP logins, the domains a login can be
     * named after, and how to connect.
     */
    public function index(Request $request): Response
    {
        $account = $request->user();

        $logins = $account->ftpAccounts()
            ->orderBy('login')
            ->get()
            ->map(fn (FtpAccount $ftpAccount): array => [
                'id' => $ftpAccount->id,
                'login' => $ftpAccount->login,
                'directory' => $ftpAccount->directory,
                'status' => $ftpAccount->status->value,
                'created_at' => $ftpAccount->created_at?->toIso8601String(),
            ]);

        return Inertia::render('ftp/index', [
            'ftp_accounts' => $logins,
            'domains' => array_map(fn (Domain $domain): array => [
                'id' => $domain->id,
                'name' => $domain->name,
            ], $this->ftp->loginDomains($account)),
            'home' => '/home/'.$account->username,
            'max_directory' => FtpDirectory::MAX_LENGTH,
            'limits' => AccountLimits::for($account)->toArray(),
            'connection' => [
                'host' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost',
                'port' => 21,
            ],
            'sftp' => [
                'username' => $account->username,
                'port' => 22,
                'password_set_at' => $account->sftp_password_set_at?->toIso8601String(),
                ...$this->sftp->syncState($account),
            ],
        ]);
    }

    /**
     * Add an FTP login; it can sign in once the agent has prepared its
     * folder.
     */
    public function store(StoreFtpAccountRequest $request): RedirectResponse
    {
        $account = $request->user();

        $ftpAccount = $this->ftp->create(
            $account,
            $request->string('name')->toString(),
            $request->integer('domain_id'),
            $request->string('directory')->toString(),
            $request->string('password')->toString(),
            $account,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('FTP account :login is being set up.', ['login' => $ftpAccount->login]),
        ]);

        return to_route('ftp.index');
    }

    /**
     * Change a login's password and folder.
     */
    public function update(UpdateFtpAccountRequest $request, FtpAccount $ftpAccount): RedirectResponse
    {
        $this->ftp->update(
            $ftpAccount,
            $request->filled('password') ? $request->string('password')->toString() : null,
            $request->string('directory')->toString(),
            $request->user(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('FTP account :login is updated.', ['login' => $ftpAccount->login]),
        ]);

        return back();
    }

    /**
     * Remove a login. Its folder and files stay.
     */
    public function destroy(Request $request, FtpAccount $ftpAccount): RedirectResponse
    {
        Gate::authorize('delete', $ftpAccount);

        $this->ftp->delete($ftpAccount, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('FTP account :login is deleted.', ['login' => $ftpAccount->login]),
        ]);

        return back();
    }
}
