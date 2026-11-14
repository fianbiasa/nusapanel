<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemBackup;
use App\Services\SystemBackupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The backups of the panel itself, on the administrators' Backup page:
 * whether they are made every day and how many are kept, a backup made
 * now, and downloading or deleting one.
 */
class SystemBackupController extends Controller
{
    public function __construct(private SystemBackupService $backups) {}

    /**
     * Turn the daily backups of the panel on or off, and say how many are
     * kept.
     */
    public function updateSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'keep' => ['required', 'integer', 'between:1,'.SystemBackupService::MAX_KEEP],
        ]);

        $this->backups->saveSettings((bool) $validated['enabled'], (int) $validated['keep'], $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Settings saved.')]);

        return back();
    }

    /**
     * Back up the panel now.
     */
    public function store(Request $request): RedirectResponse
    {
        $backup = $this->backups->start($request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Backing up the panel; :name appears here when it is done.', ['name' => $backup->name])]);

        return back();
    }

    /**
     * Send a backup of the panel.
     */
    public function download(Request $request, SystemBackup $systemBackup): BinaryFileResponse
    {
        $download = $this->backups->download($systemBackup, $request->user());

        return response()->download($download['file'], $download['name'], ['Content-Type' => 'application/gzip'])->deleteFileAfterSend();
    }

    /**
     * Delete a backup of the panel for good.
     */
    public function destroy(Request $request, SystemBackup $systemBackup): RedirectResponse
    {
        $this->backups->delete($systemBackup, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Backup :name deleted.', ['name' => $systemBackup->name])]);

        return back();
    }
}
