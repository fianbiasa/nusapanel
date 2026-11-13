<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDatabaseRequest;
use App\Http\Requests\StoreDatabaseUserRequest;
use App\Http\Resources\DatabaseResource;
use App\Http\Resources\DatabaseUserResource;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Services\DatabaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The account's MariaDB databases and users, as on the panel's databases
 * page. Creating and deleting go on in the background: the status tells
 * when they are done.
 */
class DatabaseController extends Controller
{
    public function __construct(private DatabaseService $databases) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return DatabaseResource::collection($request->user()->databases()->with('users')->orderBy('name')->get());
    }

    /**
     * Create a database, and with create_user also a user for it.
     */
    public function store(StoreDatabaseRequest $request): JsonResponse
    {
        $account = $request->user();

        $database = $this->databases->createDatabase($account, $request->string('name')->toString(), $account, $request->newUser());

        return (new DatabaseResource($database->load('users')))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, Database $database): Response
    {
        Gate::authorize('delete', $database);

        $this->databases->deleteDatabase($database, $request->user());

        return response()->noContent(202);
    }

    public function users(Request $request): AnonymousResourceCollection
    {
        return DatabaseUserResource::collection($request->user()->databaseUsers()->with('databases')->orderBy('name')->get());
    }

    /**
     * Create a database user that may use the given databases (their ids).
     */
    public function storeUser(StoreDatabaseUserRequest $request): JsonResponse
    {
        $account = $request->user();

        $user = $this->databases->createUser(
            $account,
            $request->string('name')->toString(),
            $request->string('password')->toString(),
            array_values(array_map(intval(...), $request->array('databases'))),
            $account,
        );

        return (new DatabaseUserResource($user->load('databases')))->response()->setStatusCode(201);
    }

    public function destroyUser(Request $request, DatabaseUser $databaseUser): Response
    {
        Gate::authorize('delete', $databaseUser);

        $this->databases->deleteUser($databaseUser, $request->user());

        return response()->noContent(202);
    }
}
