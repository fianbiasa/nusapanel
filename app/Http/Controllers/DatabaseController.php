<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDatabaseRequest;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Services\DatabaseService;
use App\Support\AccountLimits;
use App\Support\DatabaseNames;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class DatabaseController extends Controller
{
    public function __construct(private DatabaseService $databases) {}

    /**
     * List the signed-in account's databases and database users, with what
     * the account's package allows and how to connect.
     */
    public function index(Request $request): Response
    {
        $account = $request->user();

        $databases = $account->databases()
            ->with(['users' => fn ($query) => $query->orderBy('name')])
            ->orderBy('name')
            ->get()
            ->map(fn (Database $database): array => [
                'id' => $database->id,
                'name' => $database->name,
                'status' => $database->status->value,
                'size_bytes' => $database->size_bytes,
                'measured_at' => $database->measured_at?->toIso8601String(),
                'users' => $database->users->pluck('name')->all(),
                'created_at' => $database->created_at?->toIso8601String(),
            ]);

        $users = $account->databaseUsers()
            ->with(['databases' => fn ($query) => $query->orderBy('name')])
            ->orderBy('name')
            ->get()
            ->map(fn (DatabaseUser $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'status' => $user->status->value,
                'database_ids' => $user->databases->pluck('id')->all(),
                'created_at' => $user->created_at?->toIso8601String(),
            ]);

        return Inertia::render('databases/index', [
            'databases' => $databases,
            'database_users' => $users,
            'prefix' => $account->username.'_',
            'max_lengths' => [
                'database' => DatabaseNames::MAX_DATABASE - strlen($account->username) - 1,
                'user' => DatabaseNames::MAX_USER - strlen($account->username) - 1,
            ],
            'limits' => AccountLimits::for($account)->toArray(),
            'connection' => ['host' => 'localhost', 'port' => 3306],
        ]);
    }

    /**
     * Add a database, optionally with a new user; the agent creates them in
     * the background.
     */
    public function store(StoreDatabaseRequest $request): RedirectResponse
    {
        $account = $request->user();

        $database = $this->databases->createDatabase(
            $account,
            $request->string('name')->toString(),
            $account,
            $request->newUser(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Database :name is being created.', ['name' => $database->name]),
        ]);

        return to_route('databases.index');
    }

    /**
     * Drop a database with everything in it.
     */
    public function destroy(Request $request, Database $database): RedirectResponse
    {
        Gate::authorize('delete', $database);

        $this->databases->deleteDatabase($database, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Database :name is being deleted.', ['name' => $database->name]),
        ]);

        return back();
    }
}
