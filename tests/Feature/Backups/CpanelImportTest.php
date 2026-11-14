<?php

namespace Tests\Feature\Backups;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Enums\AccountStatus;
use App\Enums\TaskStatus;
use App\Jobs\ImportCpanelAccount;
use App\Models\AccountRestore;
use App\Models\AuditLog;
use App\Models\CpanelImport;
use App\Models\Domain;
use App\Models\Package;
use App\Models\ServerBackup;
use App\Models\Task;
use App\Models\User;
use App\Services\CpanelImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Hosting accounts imported from a cPanel backup copied into the backups
 * folder: choosing the file, and the import the job runs, which turns what
 * cPanel kept into a RAYXO backup the account is made again from.
 */
class CpanelImportTest extends TestCase
{
    use RefreshDatabase;

    private const FILE = 'cpmove-cptest1.tar.gz';

    private User $admin;

    /**
     * @var list<array{0: string, 1: array<string, mixed>}>
     */
    private array $agentCalls = [];

    /**
     * @var array<string, mixed>
     */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->travelTo('2026-11-14 10:00:00');

        $this->admin = User::factory()->admin()->create();
        $this->answers['php.versions'] = ['versions' => ['8.1', '8.2', '8.3']];
        $this->answers['backup.server_list'] = ['backups' => [['name' => self::FILE, 'size' => 43873, 'modified' => 1794659300]]];
        $this->answers['backup.cpanel_inspect'] = $this->inspection();
        $this->answers['backup.cpanel_convert'] = fn (array $params): array => [
            'name' => $params['name'],
            'size' => 52000,
            'manifest' => (string) json_encode($params['manifest']),
            'settings' => (string) json_encode($params['settings']),
        ];
        // The backup made is read again when the account is made from it.
        $this->answers['backup.server_inspect'] = function (): array {
            [, $converted] = collect($this->agentCalls)->last(fn (array $call): bool => $call[0] === 'backup.cpanel_convert');

            return ['manifest' => (string) json_encode($converted['manifest']), 'settings' => (string) json_encode($converted['settings'])];
        };

        $this->mock(AgentClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('call')->andReturnUsing(function (string $method, array $params = []): array {
                $this->agentCalls[] = [$method, $params];
                $answer = $this->answers[$method] ?? [];

                if ($answer instanceof AgentException) {
                    throw $answer;
                }

                return is_callable($answer) ? $answer($params) : $answer;
            });
        });
    }

    public function test_the_page_offers_the_files_the_panel_does_not_know_and_shows_the_imports()
    {
        $known = ServerBackup::factory()->create(['name' => 'budi-2026-11-13-020000.tar.gz']);
        $this->answers['backup.server_list'] = ['backups' => [
            ['name' => $known->name, 'size' => 10, 'modified' => 1794600000],
            ['name' => 'cpmove-old.tar.gz', 'size' => 20, 'modified' => 1794500000],
            ['name' => self::FILE, 'size' => 43873, 'modified' => 1794659300],
        ]];
        CpanelImport::factory()->create(['file' => 'cpmove-old.tar.gz', 'status' => CpanelImport::STATUS_FAILED, 'error' => 'File ini bukan backup cPanel.']);

        $this->actingAs($this->admin)->get(route('admin.transfers.cpanel.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/transfers/cpanel')
                ->has('files.items', 2)
                ->where('files.items.0.name', self::FILE)
                ->where('files.items.1.name', 'cpmove-old.tar.gz')
                ->where('imports.0.status', CpanelImport::STATUS_FAILED)
                ->where('going', false)
                ->where('folder', config('hosting.backups.dir')));

        $reseller = User::factory()->reseller()->create();
        $this->actingAs($reseller)->get(route('admin.transfers.cpanel.index'))->assertForbidden();
    }

    public function test_an_import_shows_how_the_restore_of_its_account_went()
    {
        $done = AccountRestore::factory()->create(['status' => AccountRestore::STATUS_RESTORING, 'skipped' => ['shop.cptest1.example: Its domain did not come back.']]);
        Task::create(['user_id' => $done->user_id, 'action' => 'backup.server_restore', 'payload' => [], 'status' => TaskStatus::Success]);
        CpanelImport::factory()->create(['status' => CpanelImport::STATUS_RESTORING, 'server_backup_id' => $done->server_backup_id, 'user_id' => $done->user_id, 'notes' => ['DNS zones are made new here.']]);

        $broken = AccountRestore::factory()->create(['status' => AccountRestore::STATUS_RESTORING]);
        Task::create(['user_id' => $broken->user_id, 'action' => 'backup.server_restore', 'payload' => [], 'status' => TaskStatus::Failed])->forceFill(['error' => 'disk full'])->save();
        CpanelImport::factory()->create(['status' => CpanelImport::STATUS_RESTORING, 'server_backup_id' => $broken->server_backup_id, 'user_id' => $broken->user_id]);

        $this->actingAs($this->admin)->get(route('admin.transfers.cpanel.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('imports.0.status', 'failed')
                ->where('imports.0.error', 'disk full')
                ->where('imports.1.status', 'completed')
                ->where('imports.1.notes', ['DNS zones are made new here.', 'shop.cptest1.example: Its domain did not come back.'])
                ->where('going', false));

        // A restore still running keeps the page refreshing itself.
        Task::query()->where('user_id', $done->user_id)->update(['status' => TaskStatus::Running]);

        $this->actingAs($this->admin)->get(route('admin.transfers.cpanel.index'))
            ->assertInertia(fn (Assert $page) => $page->where('imports.1.status', 'restoring')->where('going', true));
    }

    public function test_an_import_is_queued_for_a_file_in_the_folder_only()
    {
        $this->actingAs($this->admin)->post(route('admin.transfers.cpanel.store'), ['file' => 'cpmove-gone.tar.gz', 'password' => 'Kata-Sandi-Baru-2026'])
            ->assertSessionHasErrors(['file' => 'This file is not in the backups folder.']);

        $this->actingAs($this->admin)->post(route('admin.transfers.cpanel.store'), ['file' => self::FILE, 'username' => 'baru', 'password' => 'Kata-Sandi-Baru-2026'])
            ->assertSessionHasNoErrors();

        $import = CpanelImport::query()->sole();
        $this->assertSame([self::FILE, 'baru', CpanelImport::STATUS_QUEUED, $this->admin->id], [$import->file, $import->username, $import->status, $import->actor_id]);
        $this->assertTrue(Hash::check('Kata-Sandi-Baru-2026', (string) $import->password_hash));
        Queue::assertPushed(ImportCpanelAccount::class, fn (ImportCpanelAccount $job): bool => $job->import->is($import));
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'backup.cpanel_import_started', 'actor_id' => $this->admin->id]);

        // The same file is not imported twice at once.
        $this->actingAs($this->admin)->post(route('admin.transfers.cpanel.store'), ['file' => self::FILE, 'username' => 'lain', 'password' => 'Kata-Sandi-Baru-2026'])
            ->assertSessionHasErrors(['file' => 'This file is being imported already.']);
    }

    public function test_an_import_turns_the_cpanel_account_into_one_here()
    {
        $package = Package::factory()->for($this->admin, 'owner')->create();
        $import = CpanelImport::factory()->create(['file' => self::FILE, 'actor_id' => $this->admin->id, 'package_id' => $package->id]);

        app(CpanelImportService::class)->run($import);

        $import->refresh();
        $this->assertSame([CpanelImport::STATUS_RESTORING, null, 'cptest1'], [$import->status, $import->password_hash, $import->cpanel_user], (string) $import->error);

        [, $converted] = collect($this->agentCalls)->firstWhere(0, 'backup.cpanel_convert');
        $this->assertSame([self::FILE, 'cpmove-cptest1'], [$converted['source'], $converted['top']]);
        $this->assertMatchesRegularExpression('/^cptest1-2026-11-14-\d{6}-cpanel\.tar\.gz$/', $converted['name']);
        $this->assertSame(['format' => 1, 'username' => 'cptest1', 'files' => true, 'email' => true, 'databases' => ['cptest1_shop']], array_intersect_key($converted['manifest'], array_flip(['format', 'username', 'files', 'email', 'databases'])));

        $settings = $converted['settings'];
        $this->assertSame([
            ['name' => 'cptest1.example', 'type' => 'domain', 'parent' => null, 'pointer_mode' => null, 'php_version' => '8.2', 'is_primary' => true],
            ['name' => 'addon.example', 'type' => 'domain', 'parent' => null, 'pointer_mode' => null, 'php_version' => null, 'is_primary' => false],
            ['name' => 'blog.cptest1.example', 'type' => 'subdomain', 'parent' => 'cptest1.example', 'pointer_mode' => null, 'php_version' => '8.2', 'is_primary' => false],
            ['name' => 'alias.example', 'type' => 'pointer', 'parent' => 'cptest1.example', 'pointer_mode' => 'alias', 'php_version' => null, 'is_primary' => false],
        ], $settings['domains']);
        $this->assertSame([['from' => 'cptest1_shop', 'to' => 'cptest1_shop']], $converted['databases']);
        $this->assertSame([['name' => 'cptest1_wp', 'password_hash' => $this->databaseHash('wp-secret'), 'databases' => ['cptest1_shop']]], $settings['database_users']);
        $this->assertSame([
            ['domain' => 'cptest1.example', 'local_part' => 'info', 'password_hash' => $this->mailboxHash('info'), 'quota_mb' => 500],
            ['domain' => 'addon.example', 'local_part' => 'sales', 'password_hash' => $this->mailboxHash('sales'), 'quota_mb' => null],
        ], $settings['mailboxes']);
        $this->assertSame([
            ['domain' => 'cptest1.example', 'local_part' => 'billing', 'destinations' => ['owner@example.net']],
            ['domain' => 'cptest1.example', 'local_part' => 'hook', 'destinations' => ['boss@example.net']],
        ], $settings['forwarders']);
        $this->assertSame([
            ['schedule' => '*/30 * * * *', 'command' => 'php /home/cptest1/domains/addon.example/public_html/cron.php', 'description' => 'cPanel', 'enabled' => true],
            ['schedule' => '@daily', 'command' => '/home/cptest1/bin/report.sh > /dev/null', 'description' => 'cPanel', 'enabled' => true],
        ], $settings['cron_jobs']);
        $this->assertSame([
            ['from' => 'public_html', 'to' => 'domains/cptest1.example/public_html', 'mail' => false],
            ['from' => 'public_html/addon.example', 'to' => 'domains/addon.example/public_html', 'mail' => false],
            ['from' => 'public_html/blog.cptest1.example', 'to' => 'domains/blog.cptest1.example/public_html', 'mail' => false],
            ['from' => 'mail/cptest1.example/info', 'to' => 'imap/cptest1.example/info/Maildir', 'mail' => true],
            ['from' => 'mail/addon.example/sales', 'to' => 'imap/addon.example/sales/Maildir', 'mail' => true],
            ['from' => 'bin', 'to' => 'bin', 'mail' => false],
        ], $converted['rules']);

        $this->assertSame([
            'addon.example used PHP 7.4, which this server does not have: it gets the default version.',
            'Mailbox team@blog.cptest1.example is left out: mail here is for domains, not subdomains or parked domains.',
            'SSH keys (.ssh) are left out: add them again in the panel.',
            'The folder domains of the cPanel home is left out: the panel uses that name.',
            'Database user cptest1_new is left out: its password cannot be moved; make it again.',
            'The cPanel login cptest1 to MySQL is not moved: websites that sign in with it need another database user.',
            'Forwarder hook@cptest1.example loses |/usr/local/bin/php /home/cptest1/hook.php: only forwarding to addresses comes along.',
            'The catch-all of addon.example is left out: set it again in the panel.',
            'Forwarder news@blog.cptest1.example is left out: mail here is for domains, not subdomains or parked domains.',
            'Cron job "echo up" is left out: its schedule @reboot does not exist here.',
            'Cron jobs changed to use the folders here: 1.',
            'DNS zones are made new here: records added in cPanel by hand (such as MX or TXT) must be added again.',
        ], $import->notes);

        $account = User::query()->where('username', 'cptest1')->sole();
        $this->assertSame([AccountStatus::Pending, 'cptest1@example.net', $package->id, $this->admin->id, $import->user_id], [$account->status, $account->email, $account->package_id, $account->owner_id, $account->id]);
        $this->assertTrue(Hash::check('Kata-Sandi-Baru-2026', $account->password));
        $this->assertSame('cptest1.example', $account->domains()->where('is_primary', true)->value('name'));
        $this->assertSame(AccountRestore::STATUS_WAITING, AccountRestore::query()->sole()->status);
        $backup = ServerBackup::query()->findOrFail($import->server_backup_id);
        $this->assertSame(['cptest1', $converted['name']], [$backup->username, $backup->name]);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'backup.cpanel_imported', 'actor_id' => $this->admin->id]);
    }

    public function test_an_account_imported_under_another_username_gets_its_names()
    {
        $import = CpanelImport::factory()->create(['file' => self::FILE, 'actor_id' => $this->admin->id, 'username' => 'baru', 'email' => 'baru@example.com']);

        app(CpanelImportService::class)->run($import);

        $this->assertSame(CpanelImport::STATUS_RESTORING, $import->refresh()->status, (string) $import->error);
        [, $converted] = collect($this->agentCalls)->firstWhere(0, 'backup.cpanel_convert');

        $this->assertSame([['from' => 'cptest1_shop', 'to' => 'baru_shop']], $converted['databases']);
        $this->assertSame(['baru_shop'], $converted['manifest']['databases']);
        $this->assertSame(['baru_wp'], array_column($converted['settings']['database_users'], 'name'));
        $this->assertSame(['baru_shop'], $converted['settings']['database_users'][0]['databases']);
        $this->assertSame([
            'php /home/baru/domains/addon.example/public_html/cron.php',
            '/home/baru/bin/report.sh > /dev/null',
        ], array_column($converted['settings']['cron_jobs'], 'command'));
        $this->assertContains('Database cptest1_shop is now baru_shop.', $import->notes);
        $this->assertContains('Database user cptest1_wp is now baru_wp.', $import->notes);
        $this->assertSame('baru@example.com', User::query()->where('username', 'baru')->sole()->email);
    }

    public function test_the_main_website_keeps_its_files_without_its_userdata()
    {
        $inspection = $this->inspection();
        unset($inspection['files']['userdata/cptest1.example']);
        $this->answers['backup.cpanel_inspect'] = $inspection;
        $import = CpanelImport::factory()->create(['file' => self::FILE, 'actor_id' => $this->admin->id]);

        app(CpanelImportService::class)->run($import);

        [, $converted] = collect($this->agentCalls)->firstWhere(0, 'backup.cpanel_convert');
        $this->assertSame(['from' => 'public_html', 'to' => 'domains/cptest1.example/public_html', 'mail' => false], $converted['rules'][0]);
        $this->assertNull($converted['settings']['domains'][0]['php_version']);
    }

    public function test_an_import_stops_before_converting_what_cannot_be_made_here()
    {
        User::factory()->create(['username' => 'cptest1']);
        $taken = CpanelImport::factory()->create(['file' => self::FILE, 'actor_id' => $this->admin->id]);

        app(CpanelImportService::class)->run($taken);

        $this->assertSame([CpanelImport::STATUS_FAILED, 'The username cptest1 is taken; enter another username.', null], [$taken->refresh()->status, $taken->error, $taken->password_hash]);

        Domain::factory()->create(['name' => 'cptest1.example']);
        $domain = CpanelImport::factory()->create(['file' => self::FILE, 'actor_id' => $this->admin->id, 'username' => 'baru']);

        app(CpanelImportService::class)->run($domain);

        $this->assertSame('The domain cptest1.example is on this server already.', $domain->refresh()->error);
        $this->assertNull(collect($this->agentCalls)->firstWhere(0, 'backup.cpanel_convert'));
        $this->assertFalse(User::query()->where('username', 'baru')->exists());
    }

    public function test_a_file_that_is_not_a_cpanel_backup_fails_the_import()
    {
        $this->answers['backup.cpanel_inspect'] = new AgentException('the file is not a cPanel backup', reason: 'invalid');
        $import = CpanelImport::factory()->create(['file' => self::FILE, 'actor_id' => $this->admin->id]);

        app(CpanelImportService::class)->run($import);

        $this->assertSame([CpanelImport::STATUS_FAILED, 'the file is not a cPanel backup'], [$import->refresh()->status, $import->error]);
    }

    public function test_an_import_whose_job_died_fails_after_a_while()
    {
        $import = CpanelImport::factory()->create(['status' => CpanelImport::STATUS_CONVERTING]);
        $this->travel(14)->hours();

        app(CpanelImportService::class)->closeStale();

        $this->assertSame([CpanelImport::STATUS_FAILED, 'The import did not finish.'], [$import->refresh()->status, $import->error]);
    }

    /**
     * What the agent reads in a cPanel backup of cptest1: a main domain,
     * an addon domain, a subdomain and a parked domain, a database with
     * its users, mailboxes, forwarders and cron jobs.
     *
     * @return array<string, mixed>
     */
    private function inspection(): array
    {
        $auth = [
            'cptest1_wp' => ['localhost' => ['auth_plugin' => 'mysql_native_password', 'pass_hash' => bin2hex($this->databaseHash('wp-secret'))]],
            'cptest1_new' => ['localhost' => ['auth_plugin' => 'caching_sha2_password', 'pass_hash' => bin2hex('$A$005$salt')]],
        ];

        return [
            'top' => 'cpmove-cptest1',
            'files' => [
                'cp/cptest1' => "USER=cptest1\nDNS=cptest1.example\nCONTACTEMAIL=cptest1@example.net\nIP=203.0.113.10\n",
                'userdata/main' => "---\naddon_domains:\n  addon.example: addon.cptest1.example\nmain_domain: cptest1.example\nparked_domains:\n  - alias.example\nsub_domains:\n  - blog.cptest1.example\n  - addon.cptest1.example\n",
                'userdata/cptest1.example' => "---\ncustomlog:\n  -\n    format: combined\n    target: /etc/apache2/logs/domlogs/cptest1.example\ndocumentroot: /home/cptest1/public_html\nhomedir: /home/cptest1\nphpversion: ea-php82\nservername: cptest1.example\n",
                'userdata/addon.cptest1.example' => "---\ndocumentroot: /home/cptest1/public_html/addon.example\nhomedir: /home/cptest1\nphpversion: ea-php74\n",
                'userdata/blog.cptest1.example' => "---\ndocumentroot: /home/cptest1/public_html/blog.cptest1.example\nhomedir: /home/cptest1\nphpversion: ea-php82\n",
                'va/cptest1.example' => "billing@cptest1.example: owner@example.net\nhook@cptest1.example: \"|/usr/local/bin/php /home/cptest1/hook.php\",boss@example.net\n*: cptest1\n",
                'va/addon.example' => "*: someone@example.org\n",
                'va/blog.cptest1.example' => "news@blog.cptest1.example: owner@example.net\n",
                'cron/cptest1' => "# DO NOT EDIT THIS FILE\nSHELL=\"/usr/local/cpanel/bin/jailshell\"\nMAILTO=\"\"\n*/30 * * * * /usr/local/bin/ea-php82 /home/cptest1/public_html/addon.example/cron.php\n@daily /home/cptest1/bin/report.sh > /dev/null\n@reboot echo up\n",
                'mysql.sql' => "-- cPanel mysql backup\n"
                    ."GRANT USAGE ON *.* TO 'cptest1'@'localhost' IDENTIFIED BY PASSWORD '".$this->databaseHash('cp-secret')."';\n"
                    ."GRANT ALL PRIVILEGES ON `cptest1\\_shop`.* TO 'cptest1'@'localhost';\n"
                    ."GRANT USAGE ON *.* TO 'cptest1_wp'@'localhost';\n"
                    ."GRANT ALL PRIVILEGES ON `cptest1\\_shop`.* TO 'cptest1_wp'@'localhost';\n"
                    ."GRANT USAGE ON *.* TO 'cptest1_new'@'localhost';\n",
                'mysql.sql-auth.json' => (string) json_encode($auth),
                'homedir/etc/cptest1.example/shadow' => 'info:'.$this->mailboxHash('info').":20771::::::\n",
                'homedir/etc/cptest1.example/quota' => "info:524288000\n",
                'homedir/etc/addon.example/shadow' => 'sales:'.$this->mailboxHash('sales').":20771::::::\n",
                'homedir/etc/blog.cptest1.example/shadow' => 'team:'.$this->mailboxHash('team').":20771::::::\n",
            ],
            'databases' => [['name' => 'cptest1_shop', 'size' => 2145]],
            'home' => [
                ['name' => '.cpanel', 'type' => 'directory', 'bytes' => 100],
                ['name' => '.ssh', 'type' => 'directory', 'bytes' => 400],
                ['name' => 'bin', 'type' => 'directory', 'bytes' => 50],
                ['name' => 'domains', 'type' => 'directory', 'bytes' => 10],
                ['name' => 'etc', 'type' => 'directory', 'bytes' => 300],
                ['name' => 'mail', 'type' => 'directory', 'bytes' => 3000],
                ['name' => 'public_html', 'type' => 'directory', 'bytes' => 9000],
                ['name' => 'www', 'type' => 'link', 'bytes' => 0],
            ],
            'entries' => 120,
            'bytes' => 12860,
        ];
    }

    private function mailboxHash(string $mailbox): string
    {
        return crypt($mailbox.'-secret', '$6$'.substr(md5($mailbox), 0, 16).'$');
    }

    private function databaseHash(string $password): string
    {
        return '*'.strtoupper(sha1(sha1($password, true)));
    }
}
