<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCpanelImportRequest;
use App\Models\CpanelImport;
use App\Models\Package;
use App\Models\User;
use App\Services\AccountRestoreService;
use App\Services\CpanelImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Hosting accounts imported from cPanel backups the administrator copied
 * into the backups folder (see CpanelImportService).
 */
class CpanelImportController extends Controller
{
    public function __construct(
        private CpanelImportService $imports,
        private AccountRestoreService $restores,
    ) {}

    /**
     * The files that may be cPanel backups, and the latest imports.
     */
    public function index(Request $request): Response
    {
        /** @var User $actor */
        $actor = $request->user();
        $imports = CpanelImport::query()->with('account:id,username,status')->latest('id')->limit(50)->get()->map(function (CpanelImport $import): array {
            $progress = $import->status === CpanelImport::STATUS_RESTORING && $import->server_backup_id !== null
                ? $this->restores->progressOf($import->server_backup_id)
                : null;

            return [
                'id' => $import->id,
                'file' => $import->file,
                'username' => $import->account->username ?? $import->username ?? $import->cpanel_user,
                'status' => $progress['status'] ?? $import->status,
                'error' => $import->error ?? $progress['error'] ?? null,
                'notes' => [...($import->notes ?? []), ...($progress['skipped'] ?? [])],
                'created_at' => $import->created_at?->toIso8601String(),
            ];
        });

        return Inertia::render('admin/transfers/cpanel', [
            'imports' => $imports,
            'going' => $imports->contains(fn (array $import): bool => ! in_array($import['status'], ['completed', CpanelImport::STATUS_FAILED], true)),
            'files' => function (): array {
                try {
                    return ['items' => $this->imports->files(), 'error' => null];
                } catch (ValidationException $exception) {
                    return ['items' => [], 'error' => (string) collect($exception->errors())->flatten()->first()];
                }
            },
            'packages' => Package::query()->handedOutBy($actor)->orderBy('name')->get(['id', 'name']),
            'folder' => (string) config('hosting.backups.dir'),
        ]);
    }

    /**
     * Import an account from a cPanel backup in the folder.
     */
    public function store(StoreCpanelImportRequest $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $this->imports->start([
            'file' => $request->string('file')->toString(),
            'username' => $request->input('username'),
            'email' => $request->input('email'),
            'password' => $request->string('password')->toString(),
            'package_id' => $request->filled('package_id') ? $request->integer('package_id') : null,
        ], $actor);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Importing the cPanel backup. This page shows how it goes.')]);

        return back();
    }
}
