<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDatabaseUserRequest;
use App\Http\Requests\UpdateDatabaseUserRequest;
use App\Models\DatabaseUser;
use App\Services\DatabaseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class DatabaseUserController extends Controller
{
    public function __construct(private DatabaseService $databases) {}

    /**
     * Add a database user with access to some of the account's databases.
     */
    public function store(StoreDatabaseUserRequest $request): RedirectResponse
    {
        $account = $request->user();

        $user = $this->databases->createUser(
            $account,
            $request->string('name')->toString(),
            $request->string('password')->toString(),
            array_values(array_map(intval(...), $request->array('databases'))),
            $account,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Database user :name is being created.', ['name' => $user->name]),
        ]);

        return to_route('databases.index');
    }

    /**
     * Change a database user's password and the databases it may use.
     */
    public function update(UpdateDatabaseUserRequest $request, DatabaseUser $databaseUser): RedirectResponse
    {
        $this->databases->updateUser(
            $databaseUser,
            $request->filled('password') ? $request->string('password')->toString() : null,
            array_values(array_map(intval(...), $request->array('databases'))),
            $request->user(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Database user :name is being updated.', ['name' => $databaseUser->name]),
        ]);

        return back();
    }

    /**
     * Drop a database user. The databases stay.
     */
    public function destroy(Request $request, DatabaseUser $databaseUser): RedirectResponse
    {
        Gate::authorize('delete', $databaseUser);

        $this->databases->deleteUser($databaseUser, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Database user :name is being deleted.', ['name' => $databaseUser->name]),
        ]);

        return back();
    }
}
