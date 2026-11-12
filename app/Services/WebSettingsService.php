<?php

namespace App\Services;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Enums\AccountStatus;
use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\ProtectedDirectory;
use App\Models\ProtectedDirectoryUser;
use App\Models\User;
use App\Support\AccountLimits;
use App\Support\WebSettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changes the web settings of domains and subdomains ({@see WebSettings}):
 * error pages, protected folders, hotlink protection, content types and PHP
 * extensions. The agent writes them into the website's web server
 * configuration, so every change queues the website again, with the alias
 * pointers that show it. The error pages themselves are files of the
 * account, written as its Linux user.
 */
class WebSettingsService
{
    public function __construct(
        private DomainService $domains,
        private AgentClient $agent,
        private AuditLogger $audit,
    ) {}

    /**
     * The content of the website's error pages that are turned on; null
     * for one that cannot be read, such as a deleted file.
     *
     * @return array<int, string|null>
     */
    public function errorPageContents(Domain $website): array
    {
        $contents = [];

        foreach ($website->error_pages ?? [] as $status) {
            try {
                $result = $this->agent->call('fs.read', [
                    'username' => $website->account->username,
                    'path' => WebSettings::errorPagePath($website, $status),
                ]);

                $contents[$status] = is_string($result['content'] ?? null) ? $result['content'] : null;
            } catch (AgentException) {
                $contents[$status] = null;
            }
        }

        return $contents;
    }

    /**
     * Write the page the website shows for status, as the account's user,
     * and turn it on.
     *
     * @throws ValidationException
     */
    public function saveErrorPage(Domain $website, int $status, string $content, User $actor): void
    {
        $this->ensureCanChange($website, 'content');

        if ($website->account->status !== AccountStatus::Active) {
            throw ValidationException::withMessages([
                'content' => __('Files can only be managed while the account is active.'),
            ]);
        }

        $path = WebSettings::errorPagePath($website, $status);

        $this->writeAccountFile($website->account, $path, $content);

        DB::transaction(function () use ($website, $status, $path, $actor): void {
            $locked = $this->lock($website);
            $statuses = $locked->error_pages ?? [];

            if (! in_array($status, $statuses, true)) {
                $statuses[] = $status;
                sort($statuses);

                $locked->forceFill(['error_pages' => $statuses])->save();
                $this->domains->reapplyWebsite($locked, $actor);
            }

            $this->audit->record('domain.error_page_saved', $locked, [
                'domain' => $locked->name,
                'status' => $status,
                'path' => $path,
                'username' => $locked->account->username,
            ], $actor);
        });
    }

    /**
     * Show the web server's own page for status again. The file stays in
     * the account's folder.
     *
     * @throws ValidationException
     */
    public function removeErrorPage(Domain $website, int $status, User $actor): void
    {
        $this->ensureCanChange($website, 'status');

        DB::transaction(function () use ($website, $status, $actor): void {
            $locked = $this->lock($website);
            $statuses = $locked->error_pages ?? [];

            if (! in_array($status, $statuses, true)) {
                return;
            }

            $statuses = array_values(array_diff($statuses, [$status]));

            $locked->forceFill(['error_pages' => $statuses === [] ? null : $statuses])->save();
            $this->domains->reapplyWebsite($locked, $actor);

            $this->audit->record('domain.error_page_removed', $locked, [
                'domain' => $locked->name,
                'status' => $status,
                'username' => $locked->account->username,
            ], $actor);
        });
    }

    /**
     * Ask for a login before anything in path is served. Nobody can open
     * the folder until it has a login.
     *
     * @throws ValidationException
     */
    public function protectDirectory(Domain $website, string $path, string $realm, User $actor): ProtectedDirectory
    {
        $this->ensureCanChange($website, 'path');

        try {
            return DB::transaction(function () use ($website, $path, $realm, $actor): ProtectedDirectory {
                $locked = $this->lock($website);

                if ($locked->protectedDirectories()->count() >= WebSettings::MAX_PROTECTED_DIRECTORIES) {
                    throw ValidationException::withMessages([
                        'path' => __('A website can have at most :limit protected folders.', ['limit' => WebSettings::MAX_PROTECTED_DIRECTORIES]),
                    ]);
                }

                $directory = $locked->protectedDirectories()->create(['path' => $path, 'realm' => $realm]);

                $this->domains->reapplyWebsite($locked, $actor);

                $this->audit->record('protected_directory.created', $directory, [
                    'domain' => $locked->name,
                    'path' => $path,
                    'username' => $locked->account->username,
                ], $actor);

                return $directory;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'path' => __('This folder is protected already.'),
            ]);
        }
    }

    /**
     * Open a protected folder to everyone again, removing its logins.
     *
     * @throws ValidationException
     */
    public function unprotectDirectory(ProtectedDirectory $directory, User $actor): void
    {
        $website = $directory->domain;

        $this->ensureCanChange($website, 'path');

        DB::transaction(function () use ($directory, $website, $actor): void {
            $locked = $this->lock($website);

            $directory->delete();

            $this->domains->reapplyWebsite($locked, $actor);

            $this->audit->record('protected_directory.deleted', $directory, [
                'domain' => $locked->name,
                'path' => $directory->path,
                'username' => $locked->account->username,
            ], $actor);
        });
    }

    /**
     * Add a login to a protected folder.
     *
     * @throws ValidationException
     */
    public function addLogin(ProtectedDirectory $directory, string $username, string $password, User $actor): ProtectedDirectoryUser
    {
        $website = $directory->domain;

        $this->ensureCanChange($website, 'username');

        try {
            return DB::transaction(function () use ($directory, $website, $username, $password, $actor): ProtectedDirectoryUser {
                $locked = $this->lock($website);

                if ($directory->logins()->count() >= WebSettings::MAX_PROTECTED_USERS) {
                    throw ValidationException::withMessages([
                        'username' => __('A protected folder can have at most :limit logins.', ['limit' => WebSettings::MAX_PROTECTED_USERS]),
                    ]);
                }

                $login = $directory->logins()->make(['username' => $username]);
                $login->setPassword($password);
                $login->save();

                $this->domains->reapplyWebsite($locked, $actor);

                $this->audit->record('protected_directory.login_added', $login, [
                    'domain' => $locked->name,
                    'path' => $directory->path,
                    'login' => $username,
                    'username' => $locked->account->username,
                ], $actor);

                return $login;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'username' => __('This folder already has a login with this name.'),
            ]);
        }
    }

    /**
     * Give a login of a protected folder a new password.
     *
     * @throws ValidationException
     */
    public function changeLoginPassword(ProtectedDirectoryUser $login, string $password, User $actor): void
    {
        $directory = $login->protectedDirectory;
        $website = $directory->domain;

        $this->ensureCanChange($website, 'password');

        DB::transaction(function () use ($login, $directory, $website, $password, $actor): void {
            $locked = $this->lock($website);

            $login->setPassword($password);
            $login->save();

            $this->domains->reapplyWebsite($locked, $actor);

            $this->audit->record('protected_directory.login_password_changed', $login, [
                'domain' => $locked->name,
                'path' => $directory->path,
                'login' => $login->username,
                'username' => $locked->account->username,
            ], $actor);
        });
    }

    /**
     * Remove a login of a protected folder.
     *
     * @throws ValidationException
     */
    public function removeLogin(ProtectedDirectoryUser $login, User $actor): void
    {
        $directory = $login->protectedDirectory;
        $website = $directory->domain;

        $this->ensureCanChange($website, 'username');

        DB::transaction(function () use ($login, $directory, $website, $actor): void {
            $locked = $this->lock($website);

            $login->delete();

            $this->domains->reapplyWebsite($locked, $actor);

            $this->audit->record('protected_directory.login_removed', $login, [
                'domain' => $locked->name,
                'path' => $directory->path,
                'login' => $login->username,
                'username' => $locked->account->username,
            ], $actor);
        });
    }

    /**
     * Turn hotlink protection on with these settings, or off with null.
     *
     * @param  array{extensions: list<string>, domains: list<string>, allow_direct: bool, redirect_to: string|null}|null  $hotlink
     *
     * @throws ValidationException
     */
    public function updateHotlink(Domain $website, ?array $hotlink, User $actor): void
    {
        $this->update($website, 'hotlink', $hotlink, 'domain.hotlink_changed', $actor);
    }

    /**
     * Replace the content types the website sets itself.
     *
     * @param  list<array{extension: string, type: string}>  $types
     *
     * @throws ValidationException
     */
    public function updateMimeTypes(Domain $website, array $types, User $actor): void
    {
        $this->update($website, 'mime_types', $types === [] ? null : $types, 'domain.mime_types_changed', $actor);
    }

    /**
     * Replace the extensions that also run as PHP.
     *
     * @param  list<string>  $extensions
     *
     * @throws ValidationException
     */
    public function updatePhpExtensions(Domain $website, array $extensions, User $actor): void
    {
        $this->update($website, 'php_extensions', $extensions === [] ? null : $extensions, 'domain.php_extensions_changed', $actor);
    }

    /**
     * Run the scripts in the website's cgi-bin, as the account, or stop.
     * Only a package that includes CGI lets it be turned on.
     *
     * @throws ValidationException
     */
    public function setCgi(Domain $website, bool $enabled, User $actor): void
    {
        if ($enabled && ! AccountLimits::for($website->account)->allowsCgi()) {
            throw ValidationException::withMessages([
                'cgi_enabled' => __('CGI is not included in your package.'),
            ]);
        }

        $this->update($website, 'cgi_enabled', $enabled, $enabled ? 'domain.cgi_enabled' : 'domain.cgi_disabled', $actor);
    }

    /**
     * Store one of the settings of the website and apply it when it
     * changed.
     *
     * @throws ValidationException
     */
    private function update(Domain $website, string $column, mixed $value, string $action, User $actor): void
    {
        $this->ensureCanChange($website, $column);

        DB::transaction(function () use ($website, $column, $value, $action, $actor): void {
            $locked = $this->lock($website);
            $previous = $locked->getAttribute($column);

            if ($previous == $value) {
                return;
            }

            $locked->forceFill([$column => $value])->save();
            $this->domains->reapplyWebsite($locked, $actor);

            $this->audit->record($action, $locked, [
                'domain' => $locked->name,
                $column => ['from' => $previous, 'to' => $value],
                'username' => $locked->account->username,
            ], $actor);
        });
    }

    /**
     * Only a domain or subdomain has web settings of its own; a pointer
     * shows its domain's. Changes may follow each other before the agent
     * applied the first: the single queue worker runs the website's tasks
     * in order, and each carries all of its settings.
     *
     * @throws ValidationException
     */
    private function ensureCanChange(Domain $website, string $errorKey): void
    {
        if (! $website->hasOwnFolder()) {
            throw ValidationException::withMessages([
                $errorKey => __('A pointer uses the web settings of its domain.'),
            ]);
        }

        if ($website->status === DomainStatus::Deleting) {
            throw ValidationException::withMessages([
                $errorKey => __('This domain is being deleted.'),
            ]);
        }

        if ($website->ssl_status?->isBusy()) {
            throw ValidationException::withMessages([
                $errorKey => __('Wait until the SSL certificate has been installed or removed.'),
            ]);
        }
    }

    /**
     * The website, locked for the rest of the transaction, so two changes
     * never queue its configuration from the same stale settings.
     */
    private function lock(Domain $website): Domain
    {
        return Domain::query()->lockForUpdate()->findOrFail($website->id);
    }

    /**
     * Write a file of the account as its Linux user, creating its folder
     * when it is missing.
     *
     * @throws ValidationException
     */
    private function writeAccountFile(User $account, string $path, string $content): void
    {
        try {
            $this->agent->call('fs.mkdir', ['username' => $account->username, 'path' => dirname($path)]);
        } catch (AgentException $exception) {
            if ($exception->reason !== 'exists') {
                $this->failWith($exception);
            }
        }

        try {
            $this->agent->call('fs.write', [
                'username' => $account->username,
                'path' => $path,
                'content' => $content,
                'version' => '',
            ]);
        } catch (AgentException $exception) {
            $this->failWith($exception);
        }
    }

    /**
     * @throws ValidationException
     */
    private function failWith(AgentException $exception): never
    {
        if ($exception->reason === null) {
            report($exception);
        }

        throw ValidationException::withMessages([
            'content' => FileManagerService::reasonMessage($exception->reason),
        ]);
    }
}
