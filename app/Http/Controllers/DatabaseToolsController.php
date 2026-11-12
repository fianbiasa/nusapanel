<?php

namespace App\Http\Controllers;

use App\Models\Database;
use App\Models\DatabaseRemoteHost;
use App\Rules\FilePath;
use App\Services\DatabaseToolsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Remote access, export, import and table maintenance for the signed-in
 * account's databases.
 */
class DatabaseToolsController extends Controller
{
    /**
     * Where the import dialog uploads a file before it is imported; such a
     * file is removed again afterwards.
     */
    public const IMPORT_UPLOAD_PATTERN = '/^tmp\/rayxo-import-[A-Za-z0-9-]{8,64}\.(?:sql|sql\.gz|gz)$/D';

    public function __construct(private DatabaseToolsService $tools) {}

    /**
     * Let the account's database users connect from another host too.
     */
    public function storeRemoteHost(Request $request): RedirectResponse
    {
        $request->validate(['host' => ['required', 'string', 'max:64']]);

        $host = $this->tools->addRemoteHost($request->user(), $request->string('host')->value(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Database users may connect from :host once it is applied.', ['host' => $host->host])]);

        return back();
    }

    /**
     * Stop letting the account's database users connect from a host.
     */
    public function destroyRemoteHost(Request $request, DatabaseRemoteHost $databaseRemoteHost): RedirectResponse
    {
        Gate::authorize('delete', $databaseRemoteHost);

        $this->tools->removeRemoteHost($databaseRemoteHost, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':host may no longer connect.', ['host' => $databaseRemoteHost->host])]);

        return back();
    }

    /**
     * Download the database as a gzipped SQL dump.
     */
    public function export(Request $request, Database $database): BinaryFileResponse|RedirectResponse
    {
        Gate::authorize('update', $database);

        try {
            $file = $this->tools->export($database, $request->user());
        } catch (ValidationException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => collect($exception->errors())->flatten()->first()]);

            return back();
        }

        $response = response()->download($file['file'], $file['name'], ['Content-Type' => 'application/gzip']);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response->deleteFileAfterSend();
    }

    /**
     * Run an SQL file of the account's home (plain or gzip) in the database.
     * A file the import dialog uploaded is removed afterwards.
     */
    public function import(Request $request, Database $database): RedirectResponse
    {
        Gate::authorize('update', $database);

        $request->validate([
            'path' => ['required', 'string', new FilePath, 'regex:/\.(?:sql|sql\.gz|gz)$/iD'],
        ], [
            'path.regex' => __('Choose a .sql or .sql.gz file.'),
        ]);

        $path = $request->string('path')->value();

        $this->tools->import($database, $path, $request->user(), remove: preg_match(self::IMPORT_UPLOAD_PATTERN, $path) === 1);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The import into :name has started.', ['name' => $database->name])]);

        return back();
    }

    /**
     * Check, repair, optimize or analyze the tables of the database.
     */
    public function maintain(Request $request, Database $database): JsonResponse
    {
        Gate::authorize('update', $database);

        $request->validate(['operation' => ['required', Rule::in(['check', 'repair', 'optimize', 'analyze'])]]);

        $lines = $this->tools->maintain($database, $request->string('operation')->value(), $request->user());

        return response()->json(['lines' => $lines]);
    }
}
