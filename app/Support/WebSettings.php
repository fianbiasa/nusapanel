<?php

namespace App\Support;

use App\Models\Domain;
use App\Models\ProtectedDirectory;
use App\Models\ProtectedDirectoryUser;

/**
 * The web settings of a website beyond its PHP: its own error pages,
 * folders that ask for a login, hotlink protection, content types and the
 * extensions that run as PHP. The limits and patterns mirror what the
 * agent accepts in `web.apply_domain`, which checks them again.
 */
final class WebSettings
{
    /**
     * The HTTP statuses a website may show its own page for. The 503 page
     * also stands in for 502 and 504.
     */
    public const ERROR_PAGE_STATUSES = [400, 401, 403, 404, 500, 503];

    /**
     * The folder below the document root that holds the error pages.
     */
    public const ERROR_PAGES_FOLDER = '.errors';

    /**
     * The largest error page, in bytes.
     */
    public const MAX_ERROR_PAGE_BYTES = 65_536;

    /**
     * The files the web server sends straight from disk: hotlink protection
     * covers some of them, and none of them may run as PHP.
     */
    public const STATIC_EXTENSIONS = [
        'css', 'js', 'mjs', 'map', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp',
        'woff', 'woff2', 'ttf', 'otf', 'eot', 'mp4', 'webm', 'ogg', 'mp3', 'wav', 'pdf', 'txt',
        'zip', 'gz', 'tgz', 'rar', '7z',
    ];

    /**
     * What hotlink protection covers when it is first turned on.
     */
    public const DEFAULT_HOTLINK_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'mp4', 'webm', 'mp3'];

    /**
     * The extensions every website with PHP runs as PHP.
     */
    public const ALWAYS_PHP = ['php', 'phtml'];

    public const MAX_PROTECTED_DIRECTORIES = 20;

    public const MAX_PROTECTED_USERS = 50;

    public const MAX_MIME_TYPES = 100;

    public const MAX_PHP_EXTENSIONS = 20;

    public const MAX_HOTLINK_DOMAINS = 50;

    /**
     * "/" or folders below the document root of letters, digits and ._-
     * that do not start with a dot.
     */
    public const PROTECTED_PATH_PATTERN = '/^(?:\/|(?:\/[A-Za-z0-9_-][A-Za-z0-9._-]{0,99})+)$/';

    /**
     * What the browser shows when it asks for the login: no quotes,
     * backslashes or $, and no space at either end.
     */
    public const REALM_PATTERN = '/^[A-Za-z0-9._,:()!?@&+\/-](?:[A-Za-z0-9 ._,:()!?@&+\/-]{0,62}[A-Za-z0-9._,:()!?@&+\/-])?$/';

    public const LOGIN_PATTERN = '/^[A-Za-z0-9._-]{1,64}$/';

    public const EXTENSION_PATTERN = '/^[a-z0-9]{1,16}$/';

    public const MEDIA_TYPE_PATTERN = '/^[a-z0-9][a-z0-9._+-]{0,63}\/[a-z0-9][a-z0-9._+-]{0,63}$/';

    /**
     * The path of the error page of status, relative to the home.
     */
    public static function errorPagePath(Domain $website, int $status): string
    {
        return $website->documentRoot().'/'.self::ERROR_PAGES_FOLDER."/{$status}.html";
    }

    /**
     * The web settings of a website as the agent's `web.apply_domain`
     * expects them; only those that are set, so the payload of a website
     * without them stays as it was.
     *
     * @return array<string, mixed>
     */
    public static function forAgent(Domain $website): array
    {
        $params = [];

        if ($website->error_pages !== null && $website->error_pages !== []) {
            $params['error_pages'] = $website->error_pages;
        }

        $directories = $website->protectedDirectories->sortBy('path')->values();

        if ($directories->isNotEmpty()) {
            $params['protected_directories'] = $directories->map(fn (ProtectedDirectory $directory): array => [
                'path' => $directory->path,
                'realm' => $directory->realm,
                'users' => $directory->logins->sortBy('username')->values()->map(fn (ProtectedDirectoryUser $user): array => [
                    'name' => $user->username,
                    'hash' => $user->password_hash,
                ])->all(),
            ])->all();
        }

        if ($website->hotlink !== null) {
            $params['hotlink'] = [
                'extensions' => $website->hotlink['extensions'],
                'domains' => $website->hotlink['domains'],
                'allow_direct' => $website->hotlink['allow_direct'],
                'redirect_to' => $website->hotlink['redirect_to'] ?? '',
            ];
        }

        if ($website->mime_types !== null && $website->mime_types !== []) {
            $params['mime_types'] = $website->mime_types;
        }

        if ($website->php_extensions !== null && $website->php_extensions !== []) {
            $params['php_extensions'] = $website->php_extensions;
        }

        return $params;
    }
}
