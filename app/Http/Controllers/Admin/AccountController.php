<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AccountStatus;
use App\Enums\BulkAccountAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BulkAccountActionRequest;
use App\Http\Requests\Admin\StoreAccountRequest;
use App\Models\Package;
use App\Models\User;
use App\Services\AccountService;
use App\Support\AccountLimits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function __construct(private AccountService $accounts) {}

    /**
     * List accounts, newest first, optionally filtered by username, name or
     * email, with each hosting account's disk usage as last measured.
     */
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(AccountStatus::class)],
            'package' => ['nullable', 'integer', 'min:1'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));
        $status = $validated['status'] ?? null;
        $package = isset($validated['package']) ? (int) $validated['package'] : null;

        $accounts = User::query()
            ->with(['owner:id,username', 'package:id,name,disk_mb', 'primaryDomain:id,user_id,name', 'diskUsage'])
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('username', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status))
            ->when($package !== null, fn (Builder $query) => $query->where('package_id', $package))
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (User $account): array => [
                'id' => $account->id,
                'username' => $account->username,
                'name' => $account->name,
                'email' => $account->email,
                'role' => $account->role->value,
                'status' => $account->status->value,
                'suspend_reason' => $account->suspend_reason,
                'owner' => $account->owner?->username,
                'package_id' => $account->package_id,
                'package' => $account->package?->name,
                'domain' => $account->primaryDomain?->display_name,
                'disk' => $this->diskUsage($account),
                'created_at' => $account->created_at?->toIso8601String(),
            ]);

        return Inertia::render('admin/accounts/index', [
            'accounts' => $accounts,
            'packages' => $this->packageOptions(),
            'filters' => ['search' => $search, 'status' => $status ?? '', 'package' => $package === null ? '' : (string) $package],
        ]);
    }

    /**
     * Show the form for creating a hosting account.
     */
    public function create(): Response
    {
        return Inertia::render('admin/accounts/create', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'packages' => $this->packageOptions(),
        ]);
    }

    /**
     * Create a hosting account; the agent sets it up on the server in the background.
     */
    public function store(StoreAccountRequest $request): RedirectResponse
    {
        /** @var array{username: string, name: string, email: string, password: string, domain: string|null, package_id: int|null} $data */
        $data = $request->validated();

        $account = $this->accounts->create($data, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Account :username is being created.', ['username' => $account->username]),
        ]);

        return to_route('admin.accounts.index');
    }

    /**
     * Suspend an account, with an optional reason shown to admins.
     */
    public function suspend(Request $request, User $account): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $this->accounts->suspend($account, $validated['reason'] ?? null, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Account :username suspended.', ['username' => $account->username]),
        ]);

        return back();
    }

    /**
     * Lift an account's suspension.
     */
    public function unsuspend(Request $request, User $account): RedirectResponse
    {
        $this->accounts->unsuspend($account, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Account :username unsuspended.', ['username' => $account->username]),
        ]);

        return back();
    }

    /**
     * Move a hosting account to another package, or take it off packages.
     */
    public function updatePackage(Request $request, User $account): RedirectResponse
    {
        $validated = $request->validate([
            'package_id' => ['present', 'nullable', 'integer', Rule::exists(Package::class, 'id')],
        ]);

        $package = $validated['package_id'] === null ? null : Package::query()->findOrFail($request->integer('package_id'));

        $this->accounts->changePackage($account, $package, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $package === null
                ? __('Account :username no longer has a package.', ['username' => $account->username])
                : __('Account :username is now on the :package package.', ['username' => $account->username, 'package' => $package->name]),
        ]);

        return back();
    }

    /**
     * Delete an account from the server, then from the panel.
     */
    public function destroy(Request $request, User $account): RedirectResponse
    {
        $this->accounts->delete($account, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Account :username is being deleted.', ['username' => $account->username]),
        ]);

        return back();
    }

    /**
     * Suspend, unsuspend, delete or move to another package several accounts
     * at once. Accounts the action does not fit are skipped and named in the
     * message, with the reason.
     */
    public function bulk(BulkAccountActionRequest $request): RedirectResponse
    {
        $action = BulkAccountAction::from($request->string('action')->toString());
        $package = $action === BulkAccountAction::Package && $request->filled('package_id')
            ? Package::query()->findOrFail($request->integer('package_id'))
            : null;

        /** @var list<int> $ids */
        $ids = array_map(intval(...), $request->array('ids'));

        ['done' => $done, 'skipped' => $skipped] = $this->accounts->bulk(
            $action,
            $ids,
            $request->filled('reason') ? $request->string('reason')->toString() : null,
            $package,
            $request->user(),
        );

        $replace = ['count' => count($done)];
        $messages = [];

        if ($done !== []) {
            $messages[] = match ($action) {
                BulkAccountAction::Suspend => __('Accounts suspended: :count.', $replace),
                BulkAccountAction::Unsuspend => __('Accounts unsuspended: :count.', $replace),
                BulkAccountAction::Delete => __('Accounts being deleted: :count.', $replace),
                BulkAccountAction::Package => $package === null
                    ? __('Accounts taken off packages: :count.', $replace)
                    : __('Accounts moved to the :package package: :count.', [...$replace, 'package' => $package->name]),
            };
        }

        if ($skipped !== []) {
            $reasons = collect($skipped)->map(fn (string $reason, string $username): string => "{$username} ({$reason})");

            $messages[] = __('Skipped: :accounts', ['accounts' => $reasons->take(5)->implode('; ')])
                .($reasons->count() > 5 ? ' '.__('and :count more.', ['count' => $reasons->count() - 5]) : '');
        }

        Inertia::flash('toast', [
            'type' => match (true) {
                $skipped === [] => 'success',
                $done === [] => 'error',
                default => 'warning',
            },
            'message' => $messages === [] ? __('No accounts were found.') : implode(' ', $messages),
        ]);

        return back();
    }

    /**
     * The account's disk usage and limit in megabytes; usage is null when it
     * was never measured.
     *
     * @return array{used_mb: int|null, limit_mb: int|null}
     */
    private function diskUsage(User $account): array
    {
        $limits = AccountLimits::for($account);

        return ['used_mb' => $limits->usage('disk_mb'), 'limit_mb' => $limits->limit('disk_mb')];
    }

    /**
     * The packages an account can be put on, by name.
     *
     * @return list<array{id: int, name: string}>
     */
    private function packageOptions(): array
    {
        $packages = Package::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Package $package): array => ['id' => $package->id, 'name' => $package->name]);

        return array_values($packages->all());
    }
}
