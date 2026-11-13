<?php

namespace App\Http\Controllers\Admin;

use App\Agent\AgentException;
use App\Http\Controllers\Controller;
use App\Services\SystemFileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The server's configuration files administrators edit.
 */
class SystemFileController extends Controller
{
    public function __construct(private SystemFileService $files) {}

    /**
     * List the editable files and show the chosen one.
     */
    public function index(Request $request): Response
    {
        try {
            $files = $this->files->files();
        } catch (AgentException $exception) {
            report($exception);

            return Inertia::render('admin/system-files/index', ['files' => null, 'file' => null]);
        }

        $chosen = (string) $request->query('file', $files[0]['id'] ?? '');
        $file = collect($files)->firstWhere('id', $chosen);

        try {
            $content = $file === null ? null : $this->files->read($file['id']);
        } catch (AgentException $exception) {
            report($exception);
            $content = null;
        }

        return Inertia::render('admin/system-files/index', [
            'files' => $files,
            'file' => $file === null || $content === null ? null : [...$file, ...$content],
        ]);
    }

    /**
     * Save a file.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'string', 'max:40'],
            'content' => ['present', 'nullable', 'string', 'max:262144'],
            'hash' => ['required', 'string', 'max:64'],
        ]);

        $this->files->write($validated['file'], (string) ($validated['content'] ?? ''), $validated['hash'], $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The file is saved and in use.')]);

        return to_route('admin.system-files.index', ['file' => $validated['file']]);
    }

    /**
     * Put a backup of a file back.
     */
    public function restore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'string', 'max:40'],
            'backup' => ['required', 'string', 'regex:/^[0-9]{1,20}$/'],
            'hash' => ['required', 'string', 'max:64'],
        ]);

        $this->files->restore($validated['file'], $validated['backup'], $validated['hash'], $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The backup is back in place.')]);

        return to_route('admin.system-files.index', ['file' => $validated['file']]);
    }
}
