<?php

namespace App\Http\Controllers;

use App\Rules\FilePath;
use App\Services\FileManagerService;
use App\Support\AccountLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The file manager of the signed-in account. Paths are relative to its
 * home; the agent does every operation as the account's own Linux user.
 */
class FileManagerController extends Controller
{
    /**
     * Extensions shown in the image preview.
     *
     * @var array<string, string>
     */
    private const PREVIEW_TYPES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'bmp' => 'image/bmp',
        'ico' => 'image/x-icon',
        'svg' => 'image/svg+xml',
    ];

    public function __construct(private FileManagerService $files) {}

    /**
     * A folder of the home.
     */
    public function index(Request $request): Response|RedirectResponse
    {
        $path = $request->string('path')->toString();

        if (! FilePath::isValidPath($path)) {
            return to_route('files.index');
        }

        $account = $request->user();
        $listing = null;
        $error = null;

        try {
            $listing = $this->files->list($account, $path);
        } catch (ValidationException $exception) {
            $error = collect($exception->errors())->flatten()->first();
        }

        return Inertia::render('files/index', [
            'path' => $path,
            'listing' => $listing,
            'error' => $error,
            'home' => '/home/'.$account->username,
            'chunk_bytes' => FileManagerService::chunkBytes(),
            'max_upload_bytes' => FileManagerService::chunkBytes() * FileManagerService::MAX_CHUNKS,
        ]);
    }

    /**
     * What a folder holds, biggest first, and its largest files. Counting a
     * large home takes a while, so the page shows first.
     */
    public function sizes(Request $request): Response|RedirectResponse
    {
        $path = $request->string('path')->toString();

        if (! FilePath::isValidPath($path)) {
            return to_route('files.sizes');
        }

        $account = $request->user();

        return Inertia::render('files/sizes', [
            'path' => $path,
            'sizes' => Inertia::defer(function () use ($account, $path): array {
                try {
                    return ['result' => $this->files->sizes($account, $path), 'error' => null];
                } catch (ValidationException $exception) {
                    return ['result' => null, 'error' => collect($exception->errors())->flatten()->first()];
                }
            }),
            'limits' => AccountLimits::for($account)->toArray(),
        ]);
    }

    /**
     * A text file in the editor.
     */
    public function edit(Request $request): Response|RedirectResponse
    {
        $path = $request->string('path')->toString();

        if ($path === '' || ! FilePath::isValidPath($path)) {
            return to_route('files.index');
        }

        try {
            $file = $this->files->read($request->user(), $path);
        } catch (ValidationException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => collect($exception->errors())->flatten()->first()]);

            return to_route('files.index', ['path' => dirname($path) === '.' ? '' : dirname($path)]);
        }

        return Inertia::render('files/edit', [
            'path' => $path,
            'content' => $file['content'],
            'version' => $file['version'],
            'size' => $file['size'],
        ]);
    }

    /**
     * Save a file from the editor. The editor stays open, so the answer is
     * JSON: the new version to send with the next save.
     *
     * @throws ValidationException
     */
    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'path' => ['required', 'string', new FilePath],
            'content' => ['present', 'nullable', 'string', 'max:'.(2 * 1024 * 1024)],
            'version' => ['required', 'string', 'max:64'],
        ]);

        $saved = $this->files->save($request->user(), $data['path'], (string) $data['content'], $data['version']);

        return response()->json($saved);
    }

    /**
     * Create a folder or an empty file in the current folder.
     *
     * @throws ValidationException
     */
    public function create(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'directory' => ['present', 'nullable', 'string', new FilePath],
            'name' => ['required', 'string', new FilePath(nameOnly: true)],
            'type' => ['required', Rule::in(['file', 'folder'])],
        ]);

        $folder = $data['type'] === 'folder';
        $this->files->create($request->user(), (string) $data['directory'], $data['name'], $folder);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $folder
                ? __('Folder :name created.', ['name' => $data['name']])
                : __('File :name created.', ['name' => $data['name']]),
        ]);

        return back();
    }

    /**
     * Give a file or folder another name.
     *
     * @throws ValidationException
     */
    public function rename(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'path' => ['required', 'string', new FilePath],
            'name' => ['required', 'string', new FilePath(nameOnly: true)],
        ]);

        $this->files->rename($request->user(), $data['path'], $data['name']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Renamed to :name.', ['name' => $data['name']])]);

        return back();
    }

    /**
     * Copy or move files and folders into another folder.
     *
     * @throws ValidationException
     */
    public function transfer(Request $request): RedirectResponse
    {
        $data = $request->validate([
            ...$this->pathsRules(),
            'target' => ['present', 'nullable', 'string', new FilePath],
            'move' => ['required', 'boolean'],
        ]);

        $move = (bool) $data['move'];
        $outcome = $this->files->transfer($request->user(), $data['paths'], (string) $data['target'], $move);

        $this->flashOutcome($outcome, $move ? ':count item moved.|:count items moved.' : ':count item copied.|:count items copied.');

        return back();
    }

    /**
     * Move files and folders to the trash.
     *
     * @throws ValidationException
     */
    public function delete(Request $request): RedirectResponse
    {
        $data = $request->validate($this->pathsRules());

        $outcome = $this->files->delete($request->user(), $data['paths']);

        $this->flashOutcome($outcome, ':count item moved to the trash.|:count items moved to the trash.');

        return back();
    }

    /**
     * Change the permissions of files and folders.
     *
     * @throws ValidationException
     */
    public function permissions(Request $request): RedirectResponse
    {
        $data = $request->validate([
            ...$this->pathsRules(),
            'mode' => ['required', 'string', 'regex:/^0?[0-7]{3}$/D'],
        ]);

        $outcome = $this->files->changePermissions($request->user(), $data['paths'], (int) octdec($data['mode']));

        $this->flashOutcome($outcome, 'Permissions of :count item changed.|Permissions of :count items changed.');

        return back();
    }

    /**
     * Pack files and folders of the current folder into an archive there.
     *
     * @throws ValidationException
     */
    public function compress(Request $request): RedirectResponse
    {
        $data = $request->validate([
            ...$this->pathsRules(),
            'directory' => ['present', 'nullable', 'string', new FilePath],
            'name' => ['required', 'string', new FilePath(nameOnly: true)],
            'format' => ['required', Rule::in(['zip', 'tar.gz'])],
        ]);

        $this->files->compress(
            $request->user(),
            $data['paths'],
            FileManagerService::join((string) $data['directory'], $data['name']),
            $data['format'],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Archive :name created.', ['name' => $data['name']])]);

        return back();
    }

    /**
     * Unpack an archive into a folder.
     *
     * @throws ValidationException
     */
    public function extract(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'path' => ['required', 'string', new FilePath],
            'target' => ['present', 'nullable', 'string', new FilePath],
            'overwrite' => ['required', 'boolean'],
        ]);

        $result = $this->files->extract($request->user(), $data['path'], (string) $data['target'], (bool) $data['overwrite']);

        $message = __(':files files and :folders folders extracted.', ['files' => $result['files'], 'folders' => $result['folders']]);

        if ($result['skipped'] > 0) {
            $message .= ' '.__(':count already existed and were left as they are.', ['count' => $result['skipped']]);
        }

        if ($result['refused'] > 0) {
            $message .= ' '.__(':count were refused because they would land outside the folder.', ['count' => $result['refused']]);
        }

        Inertia::flash('toast', ['type' => $result['refused'] > 0 ? 'warning' : 'success', 'message' => $message]);

        return back();
    }

    /**
     * Find files and folders by name, under a folder.
     *
     * @throws ValidationException
     */
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'path' => ['present', 'nullable', 'string', new FilePath],
            'query' => ['required', 'string', 'max:100', 'not_regex:/[\/\x00]/'],
        ]);

        return response()->json($this->files->search($request->user(), (string) $data['path'], $data['query']));
    }

    /**
     * How much files and folders hold.
     *
     * @throws ValidationException
     */
    public function size(Request $request): JsonResponse
    {
        $data = $request->validate($this->pathsRules());

        return response()->json($this->files->diskUsage($request->user(), $data['paths']));
    }

    /**
     * One chunk of an upload. The answer says whether the file is stored.
     *
     * @throws ValidationException
     */
    public function upload(Request $request): JsonResponse
    {
        $data = $request->validate([
            'upload_id' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{16,64}$/D'],
            'path' => ['required', 'string', new FilePath],
            'index' => ['required', 'integer', 'min:0'],
            'total' => ['required', 'integer', 'min:1', 'max:'.FileManagerService::MAX_CHUNKS],
            'overwrite' => ['required', 'boolean'],
            'chunk' => ['required', 'file', 'max:'.intdiv(FileManagerService::chunkBytes(), 1024)],
        ]);

        $entry = $this->files->receiveChunk(
            $request->user(),
            $data['upload_id'],
            (int) $data['index'],
            (int) $data['total'],
            $request->file('chunk'),
            $data['path'],
            (bool) $data['overwrite'],
        );

        return response()->json(['stored' => $entry !== null, 'entry' => $entry]);
    }

    /**
     * Send a file to the browser.
     */
    public function download(Request $request): BinaryFileResponse|RedirectResponse
    {
        return $this->send($request, inline: false);
    }

    /**
     * Show an image. It runs in a sandbox, so an SVG cannot run scripts in
     * the panel.
     */
    public function preview(Request $request): BinaryFileResponse|RedirectResponse
    {
        return $this->send($request, inline: true);
    }

    /**
     * The deleted files and folders.
     */
    public function trash(Request $request): Response
    {
        $items = [];
        $error = null;

        try {
            $items = $this->files->trash($request->user());
        } catch (ValidationException $exception) {
            $error = collect($exception->errors())->flatten()->first();
        }

        return Inertia::render('files/trash', ['items' => $items, 'error' => $error]);
    }

    /**
     * Put deleted items back where they were.
     *
     * @throws ValidationException
     */
    public function restore(Request $request): RedirectResponse
    {
        $data = $request->validate($this->idsRules());

        $outcome = $this->files->restore($request->user(), $data['ids']);

        $this->flashOutcome($outcome, ':count item restored.|:count items restored.');

        return back();
    }

    /**
     * Remove deleted items for good, or everything in the trash.
     *
     * @throws ValidationException
     */
    public function purge(Request $request): RedirectResponse
    {
        $everything = $request->boolean('all');
        $ids = $everything ? null : $request->validate($this->idsRules())['ids'];

        $outcome = $this->files->purge($request->user(), $ids);

        $this->flashOutcome($outcome, ':count item deleted for good.|:count items deleted for good.');

        return back();
    }

    private function send(Request $request, bool $inline): BinaryFileResponse|RedirectResponse
    {
        $path = $request->string('path')->toString();
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($path === '' || ! FilePath::isValidPath($path) || ($inline && ! isset(self::PREVIEW_TYPES[$extension]))) {
            abort(404);
        }

        try {
            $file = $this->files->download($request->user(), $path);
        } catch (ValidationException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => collect($exception->errors())->flatten()->first()]);

            return back();
        }

        $response = $inline
            ? response()->file($file['file'], [
                'Content-Type' => self::PREVIEW_TYPES[$extension],
                'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            ])
            : response()->download($file['file'], $file['name']);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response->deleteFileAfterSend();
    }

    /**
     * Toast what a change of several items did: how many it changed, and
     * the first item it could not.
     *
     * @param  array{done: list<string>, failed: list<array{path: string, message: string}>}  $outcome
     */
    private function flashOutcome(array $outcome, string $doneMessage): void
    {
        $failures = $outcome['failed'];

        if ($failures === []) {
            Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice($doneMessage, count($outcome['done']))]);

            return;
        }

        $first = $failures[0];
        $message = __(':path: :message', ['path' => $first['path'], 'message' => $first['message']]);

        if (count($failures) > 1) {
            $message .= ' '.trans_choice('(and :count more)|(and :count more)', count($failures) - 1);
        }

        if ($outcome['done'] !== []) {
            $message = trans_choice($doneMessage, count($outcome['done'])).' '.$message;
        }

        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function pathsRules(): array
    {
        return [
            'paths' => ['required', 'array', 'min:1', 'max:1000'],
            'paths.*' => ['required', 'string', 'distinct', new FilePath],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function idsRules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:1000'],
            'ids.*' => ['required', 'string', 'distinct', 'regex:/^[0-9]{8}-[0-9]{6}-[0-9a-f]{8}$/D'],
        ];
    }
}
