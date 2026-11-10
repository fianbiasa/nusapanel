<?php

namespace App\Services;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Enums\AccountStatus;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The file manager. Every operation is an fs.* call to the agent, which
 * runs it as the account's Linux user inside its home, so the operating
 * system decides what the account may touch. Nothing is queued: the
 * account waits for the answer and sees right away what went wrong.
 *
 * Uploads arrive in chunks the panel collects in the transfer folder, then
 * the agent stores them; downloads go the other way.
 */
class FileManagerService
{
    /**
     * The largest upload, in chunks (10 GB with 8 MB chunks).
     */
    public const MAX_CHUNKS = 1280;

    /**
     * How long an operation that moves data may take, in seconds: copies,
     * uploads, downloads and emptying the trash.
     */
    private const LONG_TIMEOUT = 600;

    public function __construct(
        private AgentClient $agent,
        private AuditLogger $audit,
    ) {}

    /**
     * The content of a folder: folders first, then by name.
     *
     * @return array{path: string, entries: list<array{name: string, type: string, size: int, mode: string, modified: int, target?: string, target_type?: string}>, truncated: bool}
     *
     * @throws ValidationException
     */
    public function list(User $account, string $path): array
    {
        /** @var array{path: string, entries: list<array{name: string, type: string, size: int, mode: string, modified: int, target?: string, target_type?: string}>, truncated: bool} */
        return $this->call($account, 'fs.list', ['path' => $path], 'path');
    }

    /**
     * A text file to open in the editor. The version is sent back when it
     * is saved, so a change made meanwhile is never overwritten.
     *
     * @return array{content: string, size: int, version: string}
     *
     * @throws ValidationException
     */
    public function read(User $account, string $path): array
    {
        /** @var array{content: string, size: int, version: string} */
        return $this->call($account, 'fs.read', ['path' => $path], 'path');
    }

    /**
     * Save a file from the editor, unless it changed since $version.
     *
     * @return array{size: int, version: string}
     *
     * @throws ValidationException
     */
    public function save(User $account, string $path, string $content, string $version): array
    {
        $result = $this->call($account, 'fs.write', [
            'path' => $path,
            'content' => $content,
            'version' => $version,
        ], 'content');

        $this->audit->record('file.saved', $account, ['path' => $path], $account);

        return ['size' => (int) ($result['size'] ?? 0), 'version' => (string) ($result['version'] ?? '')];
    }

    /**
     * Create an empty folder or file named $name in $directory and return
     * its path.
     *
     * @throws ValidationException
     */
    public function create(User $account, string $directory, string $name, bool $folder): string
    {
        $path = self::join($directory, $name);

        $this->call($account, $folder ? 'fs.mkdir' : 'fs.create_file', ['path' => $path], 'name');

        $this->audit->record($folder ? 'file.folder_created' : 'file.created', $account, ['path' => $path], $account);

        return $path;
    }

    /**
     * Give a file or folder another name in the same folder.
     *
     * @throws ValidationException
     */
    public function rename(User $account, string $path, string $name): void
    {
        $this->call($account, 'fs.rename', ['path' => $path, 'name' => $name], 'name');

        $this->audit->record('file.renamed', $account, ['path' => $path, 'name' => $name], $account);
    }

    /**
     * Copy or move files and folders into $destination.
     *
     * @param  list<string>  $paths
     * @return array{done: list<string>, failed: list<array{path: string, message: string}>}
     *
     * @throws ValidationException
     */
    public function transfer(User $account, array $paths, string $destination, bool $move): array
    {
        $outcome = $this->outcome($this->call($account, $move ? 'fs.move' : 'fs.copy', [
            'paths' => $paths,
            'destination' => $destination,
        ], 'target', self::LONG_TIMEOUT));

        $this->recordOutcome($move ? 'file.moved' : 'file.copied', $account, $outcome, ['destination' => $destination]);

        return $outcome;
    }

    /**
     * Move files and folders to the trash.
     *
     * @param  list<string>  $paths
     * @return array{done: list<string>, failed: list<array{path: string, message: string}>}
     *
     * @throws ValidationException
     */
    public function delete(User $account, array $paths): array
    {
        $outcome = $this->outcome($this->call($account, 'fs.delete', ['paths' => $paths], 'paths', self::LONG_TIMEOUT));

        $this->recordOutcome('file.trashed', $account, $outcome);

        return $outcome;
    }

    /**
     * Set the permissions (0 to 0777) of files and folders.
     *
     * @param  list<string>  $paths
     * @return array{done: list<string>, failed: list<array{path: string, message: string}>}
     *
     * @throws ValidationException
     */
    public function changePermissions(User $account, array $paths, int $mode): array
    {
        $outcome = $this->outcome($this->call($account, 'fs.chmod', ['paths' => $paths, 'mode' => $mode], 'mode'));

        $this->recordOutcome('file.permissions_changed', $account, $outcome, ['mode' => sprintf('%04o', $mode)]);

        return $outcome;
    }

    /**
     * The deleted files and folders, newest first.
     *
     * @return list<array{id: string, name: string, path: string, type: string, size: int, deleted_at: string}>
     *
     * @throws ValidationException
     */
    public function trash(User $account): array
    {
        /** @var list<array{id: string, name: string, path: string, type: string, size: int, deleted_at: string}> */
        return $this->call($account, 'fs.trash_list', [], 'trash')['items'] ?? [];
    }

    /**
     * Put deleted items back where they were.
     *
     * @param  list<string>  $ids
     * @return array{done: list<string>, failed: list<array{path: string, message: string}>}
     *
     * @throws ValidationException
     */
    public function restore(User $account, array $ids): array
    {
        $outcome = $this->outcome($this->call($account, 'fs.trash_restore', ['ids' => $ids], 'ids', self::LONG_TIMEOUT));

        $this->recordOutcome('file.restored', $account, $outcome);

        return $outcome;
    }

    /**
     * Remove deleted items for good, or the whole trash when $ids is null.
     *
     * @param  list<string>|null  $ids
     * @return array{done: list<string>, failed: list<array{path: string, message: string}>}
     *
     * @throws ValidationException
     */
    public function purge(User $account, ?array $ids): array
    {
        $outcome = $this->outcome($ids === null
            ? $this->call($account, 'fs.trash_empty', [], 'ids', self::LONG_TIMEOUT)
            : $this->call($account, 'fs.trash_delete', ['ids' => $ids], 'ids', self::LONG_TIMEOUT));

        $this->recordOutcome('file.purged', $account, $outcome);

        return $outcome;
    }

    /**
     * Pack files and folders into a new zip or tar.gz archive at
     * $destination.
     *
     * @param  list<string>  $paths
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function compress(User $account, array $paths, string $destination, string $format): array
    {
        $entry = $this->call($account, 'fs.compress', [
            'paths' => $paths,
            'destination' => $destination,
            'format' => $format,
        ], 'name', self::LONG_TIMEOUT);

        $this->audit->record('file.compressed', $account, ['paths' => $paths, 'archive' => $destination], $account);

        return $entry;
    }

    /**
     * Unpack an archive into a folder, which is created when needed.
     *
     * @return array{destination: string, files: int, folders: int, links: int, skipped: int, refused: int}
     *
     * @throws ValidationException
     */
    public function extract(User $account, string $path, string $destination, bool $overwrite): array
    {
        $result = $this->call($account, 'fs.extract', [
            'path' => $path,
            'destination' => $destination,
            'overwrite' => $overwrite,
        ], 'target', self::LONG_TIMEOUT);

        $this->audit->record('file.extracted', $account, [
            'archive' => $path,
            'destination' => $destination,
            'overwrite' => $overwrite,
        ], $account);

        /** @var array{destination: string, files: int, folders: int, links: int, skipped: int, refused: int} */
        return $result;
    }

    /**
     * The files and folders under $path whose name contains $query.
     *
     * @return array{hits: list<array{path: string, type: string, size: int, modified: int}>, truncated: bool}
     *
     * @throws ValidationException
     */
    public function search(User $account, string $path, string $query): array
    {
        $result = $this->call($account, 'fs.search', ['path' => $path, 'query' => $query], 'query', self::LONG_TIMEOUT);

        /** @var list<array{path: string, type: string, size: int, modified: int}> $hits */
        $hits = $result['hits'] ?? [];

        return ['hits' => $hits, 'truncated' => (bool) ($result['truncated'] ?? false)];
    }

    /**
     * How much each file or folder holds.
     *
     * @param  list<string>  $paths
     * @return array{usage: list<array{path: string, size: int, files: int, folders: int, truncated: bool}>, failed: list<array{path: string, message: string}>}
     *
     * @throws ValidationException
     */
    public function diskUsage(User $account, array $paths): array
    {
        $result = $this->call($account, 'fs.disk_usage', ['paths' => $paths], 'paths', self::LONG_TIMEOUT);

        /** @var list<array{path: string, size: int, files: int, folders: int, truncated: bool}> $usage */
        $usage = $result['usage'] ?? [];

        return ['usage' => $usage, 'failed' => $this->outcome(['failed' => $result['failed'] ?? []])['failed']];
    }

    /**
     * Take one chunk of an upload. Chunks come in order; once the last one
     * is in, the agent stores the file as $path and its entry is returned.
     *
     * @return array<string, mixed>|null
     *
     * @throws ValidationException
     */
    public function receiveChunk(User $account, string $uploadId, int $index, int $total, UploadedFile $chunk, string $path, bool $overwrite): ?array
    {
        $this->ensureAccountIsActive($account, 'chunk');

        $chunkBytes = self::chunkBytes();
        $size = (int) $chunk->getSize();
        $isLast = $index === $total - 1;

        if ($total < 1 || $total > self::MAX_CHUNKS || $index < 0 || $index >= $total
            || $size > $chunkBytes || (! $isLast && $size !== $chunkBytes)) {
            throw ValidationException::withMessages(['chunk' => __('The upload is not valid. Upload the file again.')]);
        }

        $transfer = self::uploadTransfer($account, $uploadId);
        $file = self::transferPath($transfer.'.upload');

        clearstatcache(true, $file);

        if ($index > 0 && (! is_file($file) || filesize($file) !== $index * $chunkBytes)) {
            throw ValidationException::withMessages(['chunk' => __('Part of the upload went missing. Upload the file again.')]);
        }

        self::ensureTransferDir();

        $source = fopen((string) $chunk->getRealPath(), 'rb');
        $target = fopen($file, $index === 0 ? 'wb' : 'ab');

        if ($source === false || $target === false) {
            throw ValidationException::withMessages(['chunk' => __('The upload could not be stored. Try again in a moment.')]);
        }

        chmod($file, 0600);
        stream_copy_to_stream($source, $target);
        fclose($source);
        fclose($target);

        if (! $isLast) {
            return null;
        }

        try {
            $entry = $this->call($account, 'fs.upload', [
                'transfer' => $transfer,
                'path' => $path,
                'overwrite' => $overwrite,
            ], 'chunk', self::LONG_TIMEOUT);
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->audit->record('file.uploaded', $account, [
            'path' => $path,
            'size' => $entry['size'] ?? null,
            'overwrite' => $overwrite,
        ], $account);

        return $entry;
    }

    /**
     * Have the agent copy a file to the transfer folder for sending. The
     * caller deletes the copy once it is sent.
     *
     * @return array{file: string, name: string}
     *
     * @throws ValidationException
     */
    public function download(User $account, string $path): array
    {
        $transfer = Str::random(48);

        $this->call($account, 'fs.download', ['transfer' => $transfer, 'path' => $path], 'path', self::LONG_TIMEOUT);

        return ['file' => self::transferPath($transfer.'.download'), 'name' => basename($path)];
    }

    public static function chunkBytes(): int
    {
        return (int) config('hosting.files.chunk_bytes');
    }

    public static function transferDir(): string
    {
        return rtrim((string) config('hosting.files.transfer_dir'), '/');
    }

    public static function transferPath(string $name): string
    {
        return self::transferDir().'/'.$name;
    }

    /**
     * The path of $name in folder $directory ("" is the home).
     */
    public static function join(string $directory, string $name): string
    {
        return $directory === '' ? $name : $directory.'/'.$name;
    }

    /**
     * The message for a reason the agent gave.
     */
    public static function reasonMessage(?string $reason): string
    {
        return match ($reason) {
            'not_found' => __('The file or folder does not exist (anymore).'),
            'exists' => __('A file or folder with that name already exists.'),
            'not_directory' => __('That is not a folder.'),
            'is_directory' => __('That is a folder, not a file.'),
            'permission_denied' => __('The account has no permission for that file or folder.'),
            'outside_home' => __('That link leads outside the home folder.'),
            'no_space' => __('The disk quota of the account is full.'),
            'too_large' => __('The file is too large for the editor (at most 2 MB).'),
            'not_text' => __('This is not a text file, so it cannot be opened in the editor.'),
            'changed' => __('The file was changed after you opened it. Reload it and make your change again.'),
            'link' => __('This is a link. Open what it points to instead.'),
            'into_itself' => __('A folder cannot be copied or moved into itself.'),
            'trash' => __('The trash can only be changed from the Trash page.'),
            'invalid' => __('That path or name is not valid.'),
            default => __('The file operation failed. Try again in a moment.'),
        };
    }

    /**
     * The transfer id of an upload: tied to the account, so nobody can add
     * to another account's upload even knowing its id.
     */
    private static function uploadTransfer(User $account, string $uploadId): string
    {
        return hash('sha256', $account->getKey().'|'.$uploadId);
    }

    /**
     * On a server the installer creates the folder (owned by root);
     * locally the panel makes its own.
     */
    private static function ensureTransferDir(): void
    {
        $dir = self::transferDir();

        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
    }

    /**
     * Call the agent for the account, turning a refusal into a validation
     * error under $errorKey.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function call(User $account, string $method, array $params, string $errorKey, ?int $timeout = null): array
    {
        $this->ensureAccountIsActive($account, $errorKey);

        try {
            return $this->agent->call($method, ['username' => $account->username, ...$params], $timeout);
        } catch (AgentException $exception) {
            if ($exception->reason === null) {
                report($exception);
            }

            throw ValidationException::withMessages([$errorKey => self::reasonMessage($exception->reason)]);
        }
    }

    /**
     * What the agent reported for each path, with messages in the
     * account's language.
     *
     * @param  array<string, mixed>  $result
     * @return array{done: list<string>, failed: list<array{path: string, message: string}>}
     */
    private function outcome(array $result): array
    {
        $done = array_values(array_filter((array) ($result['done'] ?? []), is_string(...)));
        $failed = [];

        foreach ((array) ($result['failed'] ?? []) as $failure) {
            if (is_array($failure)) {
                $failed[] = [
                    'path' => (string) ($failure['path'] ?? ''),
                    'message' => self::reasonMessage(is_string($failure['reason'] ?? null) ? $failure['reason'] : null),
                ];
            }
        }

        return ['done' => $done, 'failed' => $failed];
    }

    /**
     * @param  array{done: list<string>, failed: list<array{path: string, message: string}>}  $outcome
     * @param  array<string, mixed>  $extra
     */
    private function recordOutcome(string $action, User $account, array $outcome, array $extra = []): void
    {
        if ($outcome['done'] === []) {
            return;
        }

        $this->audit->record($action, $account, ['paths' => $outcome['done'], ...$extra], $account);
    }

    /**
     * @throws ValidationException
     */
    private function ensureAccountIsActive(User $account, string $errorKey): void
    {
        if (! $account->hasRole(Role::User) || $account->status !== AccountStatus::Active) {
            throw ValidationException::withMessages([
                $errorKey => __('Files can only be managed while the account is active.'),
            ]);
        }
    }
}
