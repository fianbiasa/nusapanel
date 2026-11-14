<?php

namespace App\Http\Controllers;

use App\Services\BackupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The signed-in hosting account's own backups, in its ~/backups.
 */
class BackupController extends Controller
{
    public function __construct(private BackupService $backups) {}

    /**
     * List the backups, and with ?restore=<name> what that one holds.
     */
    public function index(Request $request): Response
    {
        $account = $request->user();

        try {
            $backups = $this->backups->list($account);
            $error = null;
        } catch (ValidationException $exception) {
            $backups = [];
            $error = collect($exception->errors())->flatten()->first();
        }

        return Inertia::render('backups/index', [
            'backups' => $backups,
            'error' => $error,
            'busy' => $this->backups->busy($account),
            'counts' => [
                'databases' => $account->databases()->count(),
                'domains' => $account->domains()->count(),
            ],
            'inspected' => Inertia::optional(fn (): ?array => $this->inspected($request)),
        ]);
    }

    /**
     * Start a backup with the parts asked for.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'files' => ['boolean'],
            'email' => ['boolean'],
            'databases' => ['boolean'],
        ]);

        $name = $this->backups->create(
            $request->user(),
            (bool) ($validated['files'] ?? false),
            (bool) ($validated['email'] ?? false),
            (bool) ($validated['databases'] ?? false),
            $request->user(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Backup :name is being made; it appears here when it is done.', ['name' => $name])]);

        return back();
    }

    /**
     * Restore the parts of a backup asked for.
     */
    public function restore(Request $request, string $name): RedirectResponse
    {
        $validated = $request->validate([
            'files' => ['boolean'],
            'email' => ['boolean'],
            'databases' => ['array', 'max:500'],
            'databases.*' => ['string', 'max:64'],
        ]);

        $this->backups->restore(
            $request->user(),
            $name,
            (bool) ($validated['files'] ?? false),
            (bool) ($validated['email'] ?? false),
            array_values(array_unique($validated['databases'] ?? [])),
            $request->user(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Restoring :name. See Tasks for how it goes.', ['name' => $name])]);

        return to_route('backups.index');
    }

    /**
     * Delete a backup for good.
     */
    public function destroy(Request $request, string $name): RedirectResponse
    {
        $this->backups->delete($request->user(), $name, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Backup :name deleted.', ['name' => $name])]);

        return back();
    }

    /**
     * What the backup named in ?restore= holds, or the reason it cannot
     * be read.
     *
     * @return array<string, mixed>|null
     */
    private function inspected(Request $request): ?array
    {
        $name = $request->string('restore')->toString();

        if ($name === '') {
            return null;
        }

        try {
            $backup = $this->backups->inspect($request->user(), $name);
            unset($backup['settings']);

            return $backup;
        } catch (ValidationException $exception) {
            return ['name' => $name, 'error' => collect($exception->errors())->flatten()->first()];
        }
    }
}
