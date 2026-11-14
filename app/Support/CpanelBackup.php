<?php

namespace App\Support;

use App\Enums\DomainType;
use App\Enums\PointerMode;
use App\Rules\CronSchedule;
use App\Rules\ValidDomain;
use App\Services\BackupService;
use Illuminate\Validation\ValidationException;

/**
 * What a cPanel backup (cpmove-<user>.tar.gz) holds, as the agent read it
 * (backup.cpanel_inspect), and the RAYXO backup it becomes: the settings an
 * account is made from, the dumps it holds under their names here, and
 * where each part of the cPanel home goes in the home here.
 *
 * cPanel keeps the main domain's website in public_html, addon domains and
 * subdomains in the folder each was given, and the mail of each mailbox in
 * mail/<domain>/<mailbox>; here every website has domains/<name>/public_html
 * and every mailbox imap/<domain>/<mailbox>/Maildir. What cannot come along
 * is noted for the administrator.
 */
final class CpanelBackup
{
    /**
     * Folders and files at the top of a cPanel home that are cPanel's own,
     * or hold what the import makes again here (mail and its passwords,
     * certificates, logs).
     */
    public const CPANEL_FILES = [
        '.cpanel', '.cpaddons', '.cphorde', '.caldav', '.cagefs', '.cl.selector', '.lastlogin', '.contactemail',
        '.ftpquota', '.my.cnf', '.spamassassin', '.spamassassinenable', '.spamassassinboxenable', '.razor', '.pyzor',
        '.sqmaildata', '.autorespond', '.wp-toolkit', '.softaculous', '.trash', '.cache',
        'access-logs', 'cpbackup-exclude.conf', 'etc', 'logs', 'mail', 'ssl', 'tmp', 'www',
    ];

    /**
     * Folders of a home here that belong to the panel.
     */
    public const RESERVED = ['domains', 'imap', 'backups', '.rayxo-backup', '.rayxo-restore'];

    private const MAILBOX_HASH = '#^\$6\$[./0-9A-Za-z]{1,16}\$[./0-9A-Za-z]{86}$#D';

    private const DATABASE_HASH = '/^\*[0-9A-F]{40}$/D';

    private const LOCAL_PART = '/^[a-z0-9](?:[a-z0-9._+-]{0,62}[a-z0-9])?$/D';

    /**
     * @param  array<string, string>  $files  The small files read, by their name in the backup.
     * @param  list<array{name: string, size: int}>  $dumps
     * @param  list<array{name: string, type: string, bytes: int}>  $home
     */
    private function __construct(
        public readonly string $top,
        public readonly string $user,
        private readonly array $files,
        private readonly array $dumps,
        private readonly array $home,
        public readonly int $homeBytes,
    ) {}

    /**
     * Read what the agent found in a cPanel backup.
     *
     * @param  array<string, mixed>  $inspection
     *
     * @throws ValidationException
     */
    public static function read(array $inspection): self
    {
        $files = array_filter((array) ($inspection['files'] ?? []), fn (mixed $content, mixed $name): bool => is_string($name) && is_string($content), ARRAY_FILTER_USE_BOTH);
        $accounts = array_values(array_filter(array_keys($files), fn (string $name): bool => str_starts_with($name, 'cp/')));
        $user = count($accounts) === 1 ? substr($accounts[0], 3) : '';

        if (! is_string($inspection['top'] ?? null) || preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $user) !== 1) {
            throw ValidationException::withMessages(['backup' => __('This file is not a cPanel backup.')]);
        }

        $dumps = [];
        foreach ((array) ($inspection['databases'] ?? []) as $dump) {
            if (is_array($dump) && is_string($dump['name'] ?? null)) {
                $dumps[] = ['name' => $dump['name'], 'size' => (int) ($dump['size'] ?? 0)];
            }
        }

        $home = [];
        foreach ((array) ($inspection['home'] ?? []) as $entry) {
            if (is_array($entry) && is_string($entry['name'] ?? null)) {
                $home[] = ['name' => $entry['name'], 'type' => (string) ($entry['type'] ?? ''), 'bytes' => (int) ($entry['bytes'] ?? 0)];
            }
        }

        return new self($inspection['top'], $user, $files, $dumps, $home, (int) ($inspection['bytes'] ?? 0));
    }

    /**
     * The contact email address of the cPanel account.
     */
    public function contactEmail(): ?string
    {
        $email = strtolower(trim($this->account()['CONTACTEMAIL'] ?? ''));

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    /**
     * The RAYXO backup the cPanel backup becomes for account username: its
     * manifest and settings, the dumps it holds (from the name in cPanel to
     * the name here), where the parts of the home go, and what was left out.
     *
     * @param  list<string>  $phpVersions  The PHP versions this server has.
     * @return array{manifest: array<string, mixed>, settings: array<string, mixed>, databases: list<array{from: string, to: string}>, rules: list<array{from: string, to: string, mail: bool}>, notes: list<string>}
     *
     * @throws ValidationException
     */
    public function plan(string $username, array $phpVersions): array
    {
        $notes = [];
        $domains = $this->domains($phpVersions, $notes);
        $mailDomains = array_column(array_filter($domains, fn (array $domain): bool => $domain['type'] === DomainType::Domain->value), 'name');
        [$rules, $docroots] = $this->websiteRules($domains, $username, $notes);
        $databases = $this->databases($username, $notes);
        $mailboxes = $this->mailboxes($mailDomains, $notes);

        foreach ($mailboxes as $mailbox) {
            $rules[] = ['from' => 'mail/'.$mailbox['domain'].'/'.$mailbox['local_part'], 'to' => 'imap/'.$mailbox['domain'].'/'.$mailbox['local_part'].'/Maildir', 'mail' => true];
        }

        $rules = [...$rules, ...$this->homeRules(array_column($rules, 'from'), $notes)];
        $users = $this->databaseUsers($username, $databases, $notes);
        $forwarders = $this->forwarders($mailDomains, $notes);
        $cronJobs = $this->cronJobs($username, $docroots, $notes);
        $notes[] = self::note('DNS zones are made new here: records added in cPanel by hand (such as MX or TXT) must be added again.');

        return [
            'manifest' => [
                'format' => BackupService::FORMAT,
                'panel' => 'RAYXO',
                'source' => 'cPanel',
                'username' => $username,
                'created_at' => now()->toIso8601String(),
                'files' => true,
                'email' => true,
                'databases' => array_column($databases, 'to'),
            ],
            'settings' => [
                'format' => BackupService::FORMAT,
                'account' => ['name' => $this->user, 'email' => $this->contactEmail(), 'password_hash' => null, 'package' => null, 'owner' => null],
                'domains' => array_map(fn (array $domain): array => array_diff_key($domain, ['docroot' => true]), $domains),
                'databases' => array_column($databases, 'to'),
                'database_users' => $users,
                'mailboxes' => $mailboxes,
                'forwarders' => $forwarders,
                'cron_jobs' => $cronJobs,
            ],
            'databases' => $databases,
            'rules' => $rules,
            'notes' => $notes,
        ];
    }

    /**
     * The websites: the main domain, addon domains, subdomains and parked
     * domains, with the folder each had (relative to the home).
     *
     * @param  list<string>  $phpVersions
     * @param  list<string>  $notes
     * @return list<array{name: string, type: string, parent: string|null, pointer_mode: string|null, php_version: string|null, docroot: string|null, is_primary: bool}>
     *
     * @throws ValidationException
     */
    private function domains(array $phpVersions, array &$notes): array
    {
        $main = self::yaml($this->files['userdata/main'] ?? '');
        $mainDomain = strtolower(self::scalar($main['main_domain'] ?? null) ?? ($this->account()['DNS'] ?? ''));

        if (! ValidDomain::isWellFormed($mainDomain)) {
            throw ValidationException::withMessages(['backup' => __('The cPanel backup names no main domain.')]);
        }

        $addons = is_array($main['addon_domains'] ?? null) ? $main['addon_domains'] : [];
        $internal = array_map(fn (mixed $sub): string => strtolower((string) self::scalar($sub)), array_values($addons));
        $domains = [$this->domain($mainDomain, DomainType::Domain, null, $mainDomain, $phpVersions, $notes, 'public_html') + ['is_primary' => true]];
        $parents = [$mainDomain];

        foreach ($addons as $addon => $sub) {
            $addon = strtolower((string) $addon);

            if ($this->wellFormed($addon, $notes)) {
                $domains[] = $this->domain($addon, DomainType::Domain, null, strtolower((string) self::scalar($sub)), $phpVersions, $notes);
                $parents[] = $addon;
            }
        }

        foreach (self::list($main['sub_domains'] ?? null) as $sub) {
            $sub = strtolower($sub);

            if (in_array($sub, $internal, true) || ! $this->wellFormed($sub, $notes)) {
                continue;
            }

            $parent = collect($parents)->filter(fn (string $name): bool => str_ends_with($sub, '.'.$name))->sortByDesc(fn (string $name): int => strlen($name))->first();

            if ($parent === null) {
                $notes[] = self::note(':domain is left out: it is not a subdomain of a domain of the account.', ['domain' => $sub]);

                continue;
            }

            $domains[] = $this->domain($sub, DomainType::Subdomain, $parent, $sub, $phpVersions, $notes);
        }

        foreach (self::list($main['parked_domains'] ?? null) as $parked) {
            $parked = strtolower($parked);

            if ($this->wellFormed($parked, $notes)) {
                $domains[] = ['name' => $parked, 'type' => DomainType::Pointer->value, 'parent' => $mainDomain, 'pointer_mode' => PointerMode::Alias->value, 'php_version' => null, 'docroot' => null];
            }
        }

        return array_map(fn (array $domain): array => $domain + ['is_primary' => false], $domains);
    }

    /**
     * One website, with what cPanel's userdata of its virtual host says.
     *
     * @param  list<string>  $phpVersions
     * @param  list<string>  $notes
     * @param  string|null  $usual  The folder cPanel gives the website, for userdata that names none.
     * @return array{name: string, type: string, parent: string|null, pointer_mode: null, php_version: string|null, docroot: string|null}
     */
    private function domain(string $name, DomainType $type, ?string $parent, string $vhost, array $phpVersions, array &$notes, ?string $usual = null): array
    {
        $userdata = self::yaml($this->files['userdata/'.$vhost] ?? '');
        $version = null;

        if (preg_match('/^(?:ea|alt)-php(\d)(\d+)$/D', (string) self::scalar($userdata['phpversion'] ?? null), $matches) === 1) {
            $version = $matches[1].'.'.$matches[2];

            if (! in_array($version, $phpVersions, true)) {
                $notes[] = self::note(':domain used PHP :version, which this server does not have: it gets the default version.', ['domain' => $name, 'version' => $version]);
                $version = null;
            }
        }

        $home = $this->homedir();
        $root = self::scalar($userdata['documentroot'] ?? null) ?? ($usual !== null ? $home.'/'.$usual : null);
        $docroot = $root !== null && str_starts_with($root, $home.'/') ? trim(substr($root, strlen($home) + 1), '/') : null;

        if ($docroot === null || $docroot === '' || preg_match('#(^|/)\.\.?(/|$)#', $docroot) === 1) {
            $notes[] = self::note('The files of :domain are left out: its folder is not in the home.', ['domain' => $name]);
            $docroot = null;
        }

        return ['name' => $name, 'type' => $type->value, 'parent' => $parent, 'pointer_mode' => null, 'php_version' => $version, 'docroot' => $docroot];
    }

    /**
     * Where the folder of each website goes. A folder two websites share
     * goes to the first. Also returns each folder's full path then and now.
     *
     * @param  list<array{name: string, type: string, docroot: string|null}>  $domains
     * @param  list<string>  $notes
     * @return array{0: list<array{from: string, to: string, mail: bool}>, 1: array<string, string>}
     */
    private function websiteRules(array $domains, string $username, array &$notes): array
    {
        $rules = [];
        $docroots = [];

        foreach ($domains as $domain) {
            if ($domain['docroot'] === null) {
                continue;
            }

            if (isset($docroots[$domain['docroot']])) {
                $notes[] = self::note(':domain shared its folder with another website; its files went to that one.', ['domain' => $domain['name']]);

                continue;
            }

            $docroots[$domain['docroot']] = '/home/'.$username.'/domains/'.$domain['name'].'/public_html';
            $rules[] = ['from' => $domain['docroot'], 'to' => 'domains/'.$domain['name'].'/public_html', 'mail' => false];
        }

        return [$rules, $docroots];
    }

    /**
     * The other files and folders at the top of the home stay where they
     * are, but for cPanel's own and the panel's.
     *
     * @param  list<string>  $taken  Paths other rules move.
     * @param  list<string>  $notes
     * @return list<array{from: string, to: string, mail: bool}>
     */
    private function homeRules(array $taken, array &$notes): array
    {
        $rules = [];

        foreach ($this->home as $entry) {
            $name = $entry['name'];

            if (in_array($name, $taken, true) || in_array($name, self::CPANEL_FILES, true)) {
                continue;
            }

            if (in_array($name, self::RESERVED, true)) {
                $notes[] = self::note('The folder :name of the cPanel home is left out: the panel uses that name.', ['name' => $name]);

                continue;
            }

            if ($name === '.ssh') {
                $notes[] = self::note('SSH keys (.ssh) are left out: add them again in the panel.');

                continue;
            }

            $rules[] = ['from' => $name, 'to' => $name, 'mail' => false];
        }

        return $rules;
    }

    /**
     * The databases with a dump, named for the account here.
     *
     * @param  list<string>  $notes
     * @return list<array{from: string, to: string}>
     */
    private function databases(string $username, array &$notes): array
    {
        $databases = [];

        foreach ($this->dumps as $dump) {
            $to = $this->renamed($dump['name'], $username);

            if ($to === null || strlen($to) > DatabaseNames::MAX_DATABASE) {
                $notes[] = self::note('Database :name is left out: its name does not fit here.', ['name' => $dump['name']]);

                continue;
            }

            if ($to !== $dump['name']) {
                $notes[] = self::note('Database :from is now :to.', ['from' => $dump['name'], 'to' => $to]);
            }

            $databases[] = ['from' => $dump['name'], 'to' => $to];
        }

        return $databases;
    }

    /**
     * The database users, with their passwords and the databases they had.
     * The cPanel account's own MySQL login has no counterpart here.
     *
     * @param  list<array{from: string, to: string}>  $databases
     * @param  list<string>  $notes
     * @return list<array{name: string, password_hash: string, databases: list<string>}>
     */
    private function databaseUsers(string $username, array $databases, array &$notes): array
    {
        $names = array_column($databases, 'to', 'from');
        $hashes = [];
        $access = [];

        foreach ((array) json_decode($this->files['mysql.sql-auth.json'] ?? '', true) as $user => $hosts) {
            $hashes[(string) $user] = '';

            foreach ((array) $hosts as $host) {
                $hash = is_array($host) && is_string($host['pass_hash'] ?? null) && ctype_xdigit($host['pass_hash']) ? (string) hex2bin($host['pass_hash']) : '';

                if (preg_match(self::DATABASE_HASH, $hash) === 1) {
                    $hashes[(string) $user] = $hash;
                }
            }
        }

        foreach (preg_split('/\R/', $this->files['mysql.sql'] ?? '') ?: [] as $line) {
            if (preg_match("/^GRANT .+ ON (.+?)\\.\\* TO '([^']+)'@'[^']*'(?: IDENTIFIED BY PASSWORD '([^']*)')?/", $line, $matches) !== 1) {
                continue;
            }

            $user = $matches[2];
            $hashes[$user] ??= '';

            if ($hashes[$user] === '' && preg_match(self::DATABASE_HASH, $matches[3] ?? '') === 1) {
                $hashes[$user] = $matches[3];
            }

            $database = str_replace(['\\_', '\\%'], ['_', '%'], trim($matches[1], '`'));

            if (isset($names[$database])) {
                $access[$user][$names[$database]] = true;
            }
        }

        $users = [];

        foreach ($hashes as $user => $hash) {
            $user = (string) $user;

            if ($user === $this->user) {
                if (($access[$user] ?? []) !== []) {
                    $notes[] = self::note('The cPanel login :name to MySQL is not moved: websites that sign in with it need another database user.', ['name' => $user]);
                }

                continue;
            }

            $to = $this->renamed($user, $username);

            if ($to === null || strlen($to) > DatabaseNames::MAX_USER) {
                $notes[] = self::note('Database user :name is left out: its name does not fit here.', ['name' => $user]);

                continue;
            }

            if ($hash === '') {
                $notes[] = self::note('Database user :name is left out: its password cannot be moved; make it again.', ['name' => $user]);

                continue;
            }

            if ($to !== $user) {
                $notes[] = self::note('Database user :from is now :to.', ['from' => $user, 'to' => $to]);
            }

            $users[] = ['name' => $to, 'password_hash' => $hash, 'databases' => array_keys($access[$user] ?? [])];
        }

        return $users;
    }

    /**
     * The mailboxes of the account's domains, with their passwords. Mail
     * here belongs to domains, not to subdomains or parked domains.
     *
     * @param  list<string>  $mailDomains
     * @param  list<string>  $notes
     * @return list<array{domain: string, local_part: string, password_hash: string, quota_mb: int|null}>
     */
    private function mailboxes(array $mailDomains, array &$notes): array
    {
        $mailboxes = [];

        foreach ($this->files as $name => $content) {
            if (preg_match('#^homedir/etc/([^/]+)/shadow$#D', $name, $matches) !== 1) {
                continue;
            }

            $domain = strtolower($matches[1]);
            $quotas = $this->quotas($domain);

            foreach (preg_split('/\R/', $content) ?: [] as $line) {
                [$local, $hash] = array_pad(explode(':', $line, 3), 2, '');
                $local = strtolower($local);

                if ($local === '') {
                    continue;
                }

                $address = $local.'@'.$domain;

                if (! in_array($domain, $mailDomains, true)) {
                    $notes[] = self::note('Mailbox :address is left out: mail here is for domains, not subdomains or parked domains.', ['address' => $address]);
                } elseif (preg_match(self::LOCAL_PART, $local) !== 1) {
                    $notes[] = self::note('Mailbox :address is left out: its name does not fit here.', ['address' => $address]);
                } elseif (preg_match(self::MAILBOX_HASH, $hash) !== 1) {
                    $notes[] = self::note('Mailbox :address is left out: its password cannot be moved; make it again.', ['address' => $address]);
                } else {
                    $mailboxes[] = ['domain' => $domain, 'local_part' => $local, 'password_hash' => $hash, 'quota_mb' => $quotas[$local] ?? null];
                }
            }
        }

        return $mailboxes;
    }

    /**
     * The quota of each mailbox of domain, in MB; none is no limit.
     *
     * @return array<string, int>
     */
    private function quotas(string $domain): array
    {
        $quotas = [];

        foreach (preg_split('/\R/', $this->files['homedir/etc/'.$domain.'/quota'] ?? '') ?: [] as $line) {
            [$local, $bytes] = array_pad(explode(':', $line, 2), 2, '');

            if (ctype_digit(trim($bytes)) && (int) $bytes > 0) {
                $quotas[strtolower($local)] = (int) ceil((int) $bytes / 1048576);
            }
        }

        return $quotas;
    }

    /**
     * The forwarders of the account's domains that forward to addresses.
     * Pipes to programs, files and the cPanel account's own mailbox have no
     * counterpart here.
     *
     * @param  list<string>  $mailDomains
     * @param  list<string>  $notes
     * @return list<array{domain: string, local_part: string, destinations: list<string>}>
     */
    private function forwarders(array $mailDomains, array &$notes): array
    {
        $forwarders = [];

        foreach ($this->files as $name => $content) {
            if (preg_match('#^va/([^/]+)$#D', $name, $matches) !== 1) {
                continue;
            }

            $domain = strtolower($matches[1]);

            foreach (preg_split('/\R/', $content) ?: [] as $line) {
                [$address, $targets] = array_pad(array_map('trim', explode(':', $line, 2)), 2, '');
                $address = strtolower($address);

                if ($address === '' || str_starts_with($address, '#')) {
                    continue;
                }

                $destinations = [];
                $others = [];

                foreach (explode(',', $targets) as $target) {
                    $target = trim($target, " \t\"'");

                    if ($target === '' || $target === $this->user || str_starts_with($target, ':fail:') || str_starts_with($target, ':blackhole:')) {
                        continue;
                    }

                    if (MailAddress::isValid(strtolower($target))) {
                        $destinations[] = strtolower($target);
                    } else {
                        $others[] = $target;
                    }
                }

                if ($address === '*') {
                    if ($destinations !== [] || $others !== []) {
                        $notes[] = self::note('The catch-all of :domain is left out: set it again in the panel.', ['domain' => $domain]);
                    }

                    continue;
                }

                if (! str_ends_with($address, '@'.$domain) || ($destinations === [] && $others === [])) {
                    continue;
                }

                if (! in_array($domain, $mailDomains, true)) {
                    $notes[] = self::note('Forwarder :address is left out: mail here is for domains, not subdomains or parked domains.', ['address' => $address]);

                    continue;
                }

                if ($others !== []) {
                    $notes[] = self::note('Forwarder :address loses :targets: only forwarding to addresses comes along.', ['address' => $address, 'targets' => implode(', ', $others)]);
                }

                if ($destinations !== []) {
                    $forwarders[] = ['domain' => $domain, 'local_part' => substr($address, 0, -strlen('@'.$domain)), 'destinations' => array_values(array_unique($destinations))];
                }
            }
        }

        return $forwarders;
    }

    /**
     * The cron jobs, with the paths of the old home made those here: the
     * home, the folders of the websites and cPanel's PHP.
     *
     * @param  array<string, string>  $docroots  Folder in the old home => its full path here.
     * @param  list<string>  $notes
     * @return list<array{schedule: string, command: string, description: string, enabled: bool}>
     */
    private function cronJobs(string $username, array $docroots, array &$notes): array
    {
        $jobs = [];
        $changed = 0;
        $home = $this->homedir();
        uksort($docroots, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach (preg_split('/\R/', $this->files['cron/'.$this->user] ?? '') ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || preg_match('/^[A-Za-z_][A-Za-z0-9_]*\s*=/', $line) === 1) {
                continue;
            }

            $special = str_starts_with($line, '@');
            $parts = preg_split('/\s+/', $line, $special ? 2 : 6) ?: [];

            if (count($parts) !== ($special ? 2 : 6)) {
                continue;
            }

            $command = (string) array_pop($parts);
            $schedule = implode(' ', $parts);

            if ($special && ! in_array(strtolower($schedule), CronSchedule::SPECIALS, true)) {
                $notes[] = self::note('Cron job ":command" is left out: its schedule :schedule does not exist here.', ['command' => $command, 'schedule' => $schedule]);

                continue;
            }

            $moved = $command;

            foreach ($docroots as $old => $new) {
                $moved = (string) preg_replace('#'.preg_quote($home.'/'.$old, '#').'(?=[/\s\'";|&>]|$)#', $new, $moved);
            }

            $moved = (string) preg_replace('#'.preg_quote($home, '#').'(?=[/\s\'";|&>]|$)#', '/home/'.$username, $moved);
            $moved = (string) preg_replace('#(?<![\w/.-])(?:/usr/local/bin/ea-php\d+|/opt/cpanel/ea-php\d+/root/usr/bin/php(?:-cgi)?|/usr/local/bin/php)(?=\s|$)#', 'php', $moved);

            if ($moved !== $command) {
                $changed++;
            }

            $jobs[] = ['schedule' => $schedule, 'command' => $moved, 'description' => 'cPanel', 'enabled' => true];
        }

        if ($changed > 0) {
            $notes[] = self::note('Cron jobs changed to use the folders here: :count.', ['count' => $changed]);
        }

        return $jobs;
    }

    /**
     * Name, which in cPanel starts with the cPanel username, for account
     * username here. Null when it holds what names here cannot.
     */
    private function renamed(string $name, string $username): ?string
    {
        $rest = strtolower(str_starts_with($name, $this->user.'_') ? substr($name, strlen($this->user) + 1) : $name);

        return preg_match('/^[a-z0-9_]+$/D', $rest) === 1 ? $username.'_'.$rest : null;
    }

    /**
     * @param  list<string>  $notes
     */
    private function wellFormed(string $domain, array &$notes): bool
    {
        if (ValidDomain::isWellFormed($domain)) {
            return true;
        }

        $notes[] = self::note(':domain is left out: it is not a valid domain name.', ['domain' => $domain]);

        return false;
    }

    /**
     * The home of the account on the cPanel server.
     */
    private function homedir(): string
    {
        $main = self::yaml($this->files['userdata/main'] ?? '');
        $vhost = self::yaml($this->files['userdata/'.strtolower((string) self::scalar($main['main_domain'] ?? null))] ?? '');
        $home = self::scalar($vhost['homedir'] ?? null) ?? trim(strtok($this->files['homedir_paths'] ?? '', "\n") ?: '');

        return rtrim($home !== '' ? $home : '/home/'.$this->user, '/');
    }

    /**
     * What cp/<user> says of the account: KEY=value lines.
     *
     * @return array<string, string>
     */
    private function account(): array
    {
        $values = [];

        foreach (preg_split('/\R/', $this->files['cp/'.$this->user] ?? '') ?: [] as $line) {
            if (preg_match('/^([A-Z0-9_-]+)=(.*)$/D', $line, $matches) === 1) {
                $values[$matches[1]] = trim($matches[2]);
            }
        }

        return $values;
    }

    /**
     * The top of a YAML file as cPanel writes its userdata: scalars, and
     * maps and lists one level deep. Anything deeper is skipped.
     *
     * @return array<string, mixed>
     */
    public static function yaml(string $text): array
    {
        $values = [];
        $key = null;

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (trim($line) === '' || trim($line) === '---' || str_starts_with(ltrim($line), '#')) {
                continue;
            }

            if (preg_match('/^([A-Za-z0-9_.-]+):(?:\s+(.*))?$/D', $line, $matches) === 1) {
                $key = $matches[1];
                $value = trim($matches[2] ?? '');
                $values[$key] = match ($value) {
                    '', '{}', '[]' => [],
                    '~' => null,
                    default => self::unquote($value),
                };

                continue;
            }

            if ($key === null || ! is_array($values[$key]) || preg_match('/^  (\S.*)$/D', $line, $matches) !== 1) {
                continue;
            }

            if (preg_match('/^-\s+(.+)$/D', $matches[1], $item) === 1) {
                $values[$key][] = self::unquote(trim($item[1]));
            } elseif (preg_match('/^(\'[^\']*\'|"[^"]*"|[^:\s][^:]*):\s+(.+)$/D', $matches[1], $pair) === 1) {
                $values[$key][self::unquote(trim($pair[1]))] = self::unquote(trim($pair[2]));
            }
        }

        return $values;
    }

    /**
     * A note for the administrator, in their language.
     *
     * @param  array<string, string|int>  $replace
     */
    private static function note(string $key, array $replace = []): string
    {
        $text = __($key, $replace);

        return is_string($text) ? $text : $key;
    }

    private static function unquote(string $value): string
    {
        if (strlen($value) >= 2 && $value[0] === "'" && str_ends_with($value, "'")) {
            return str_replace("''", "'", substr($value, 1, -1));
        }

        if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
            return stripcslashes(substr($value, 1, -1));
        }

        return $value;
    }

    private static function scalar(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return list<string>
     */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, fn (mixed $item): bool => is_string($item) && $item !== '')) : [];
    }
}
