<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Enums\ServerBackupStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\BackupResource;
use App\Models\ServerBackup;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ServerBackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The backups the server keeps of the hosting accounts an administrator
 * or reseller manages: listing them, making one now and downloading it,
 * which is how another RAYXO server moves an account over.
 */
class BackupController extends Controller
{
    public function __construct(private ServerBackupService $backups, private AuditLogger $audit) {}

    /**
     * The account's backups, newest first.
     */
    public function index(User $account): AnonymousResourceCollection
    {
        $this->authorizeAccount($account);

        return BackupResource::collection(ServerBackup::query()->where('user_id', $account->id)->latest('id')->limit(50)->get());
    }

    /**
     * Back the account up now; the backup is ready once its status is
     * completed.
     */
    public function store(Request $request, User $account): JsonResponse
    {
        $this->authorizeAccount($account);

        return (new BackupResource($this->backups->backupNow($account, $request->user())))->response()->setStatusCode(202);
    }

    public function show(ServerBackup $serverBackup): BackupResource
    {
        Gate::authorize('use', $serverBackup);

        return new BackupResource($serverBackup);
    }

    /**
     * The backup's file.
     */
    public function download(Request $request, ServerBackup $serverBackup): BinaryFileResponse
    {
        Gate::authorize('use', $serverBackup);

        abort_unless($serverBackup->status === ServerBackupStatus::Completed && is_file($serverBackup->path()), 404);

        $this->audit->record('backup.server_downloaded', $serverBackup->account, ['name' => $serverBackup->name, 'username' => $serverBackup->username], $request->user());

        return response()->download($serverBackup->path(), $serverBackup->name, ['Content-Type' => 'application/gzip']);
    }

    private function authorizeAccount(User $account): void
    {
        Gate::authorize('manage', $account);

        abort_unless($account->hasRole(Role::User), 404);
    }
}
