<?php

namespace Tests\Feature\Domains;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Agent\FakeAgentClient;
use App\Enums\AccountStatus;
use App\Enums\DomainStatus;
use App\Enums\PointerMode;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\ProtectedDirectory;
use App\Models\ProtectedDirectoryUser;
use App\Models\Task;
use App\Models\User;
use App\Services\DomainService;
use App\Support\CryptPassword;
use App\Support\WebSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WebSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $account;

    private Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->account = User::factory()->create(['username' => 'budi']);
        $this->domain = Domain::factory()->for($this->account, 'account')->create(['name' => 'example.com']);
    }

    public function test_the_page_shows_the_web_settings_of_a_website()
    {
        $this->domain->forceFill([
            'error_pages' => [404],
            'hotlink' => ['extensions' => ['png'], 'domains' => [], 'allow_direct' => true, 'redirect_to' => null],
            'mime_types' => [['extension' => 'foo', 'type' => 'application/x-foo']],
            'php_extensions' => ['html'],
        ])->save();
        Domain::factory()->pointerOf($this->domain)->create(['name' => 'example.net']);
        $directory = ProtectedDirectory::factory()->for($this->domain)->create(['path' => '/admin', 'realm' => 'Admin']);
        ProtectedDirectoryUser::factory()->for($directory)->create(['username' => 'siti']);
        $agent = $this->app->instance(AgentClient::class, new FakeAgentClient);

        $this->actingAs($this->account)
            ->get(route('domains.web.show', $this->domain))
            ->assertInertia(fn (Assert $page) => $page
                ->component('domains/web')
                ->where('domain.document_root', 'domains/example.com/public_html')
                ->where('domain.aliases', ['example.net'])
                ->where('error_pages', [404])
                ->where('protected_directories.0.path', '/admin')
                ->where('protected_directories.0.logins', [['id' => $directory->logins()->sole()->id, 'username' => 'siti']])
                ->where('hotlink.extensions', ['png'])
                ->where('mime_types', [['extension' => 'foo', 'type' => 'application/x-foo']])
                ->where('php_extensions', ['html'])
                ->where('options.static_extensions', WebSettings::STATIC_EXTENSIONS)
                ->missing('error_page_contents')
                ->loadDeferredProps(fn (Assert $reload) => $reload
                    ->has('error_page_contents.404')));

        // Only the page that is turned on is read, as the account's user.
        $this->assertSame([[
            'method' => 'fs.read',
            'params' => ['username' => 'budi', 'path' => 'domains/example.com/public_html/.errors/404.html'],
        ]], $agent->calls());
    }

    public function test_pointers_and_other_accounts_websites_have_no_page()
    {
        $pointer = Domain::factory()->pointerOf($this->domain)->create();

        $this->actingAs($this->account)->get(route('domains.web.show', $pointer))->assertNotFound();
        $this->actingAs(User::factory()->create())->get(route('domains.web.show', $this->domain))->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('changes')]
    public function test_other_accounts_cannot_change_the_web_settings(string $method, string $route, array $data)
    {
        $directory = ProtectedDirectory::factory()->for($this->domain)->create();
        $login = ProtectedDirectoryUser::factory()->for($directory)->create();
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('call'));

        $this->actingAs(User::factory()->create())
            ->{$method}(route($route, ['domain' => $this->domain, 'status' => 404, 'protectedDirectory' => $directory, 'login' => $login]), $data)
            ->assertNotFound();

        $this->assertDatabaseCount('tasks', 0);
        $this->assertModelExists($login);
    }

    /**
     * @return array<string, array{string, string, array<string, mixed>}>
     */
    public static function changes(): array
    {
        return [
            'save an error page' => ['put', 'domains.error-pages.update', ['content' => '<h1>x</h1>']],
            'remove an error page' => ['delete', 'domains.error-pages.destroy', []],
            'protect a folder' => ['post', 'domains.protected-directories.store', ['path' => '/x', 'realm' => 'x']],
            'open a folder' => ['delete', 'domains.protected-directories.destroy', []],
            'add a login' => ['post', 'domains.protected-directories.logins.store', ['username' => 'x', 'password' => 'a-long-password-1']],
            'change a password' => ['put', 'domains.protected-directories.logins.update', ['password' => 'a-long-password-1']],
            'remove a login' => ['delete', 'domains.protected-directories.logins.destroy', []],
            'hotlink' => ['put', 'domains.hotlink.update', ['enabled' => false, 'allow_direct' => true]],
            'content types' => ['put', 'domains.mime-types.update', ['mime_types' => []]],
            'php extensions' => ['put', 'domains.php-extensions.update', ['php_extensions' => []]],
            'cgi' => ['put', 'domains.cgi.update', ['cgi_enabled' => false]],
            'limits' => ['put', 'domains.limits.update', ['bandwidth_limit_mb' => 5]],
            'csr' => ['post', 'domains.certificate-request.store', ['key_type' => 'rsa2048']],
            'self-signed' => ['post', 'domains.self-signed-certificate.store', ['key_type' => 'rsa2048', 'days' => 30]],
        ];
    }

    public function test_a_login_or_folder_of_another_website_is_not_found()
    {
        $other = Domain::factory()->for($this->account, 'account')->create();
        $foreignDirectory = ProtectedDirectory::factory()->for($other)->create();
        $directory = ProtectedDirectory::factory()->for($this->domain)->create();
        $foreignLogin = ProtectedDirectoryUser::factory()->for($foreignDirectory)->create();

        $this->actingAs($this->account)
            ->delete(route('domains.protected-directories.destroy', [$this->domain, $foreignDirectory]))
            ->assertNotFound();

        $this->actingAs($this->account)
            ->delete(route('domains.protected-directories.logins.destroy', [$this->domain, $directory, $foreignLogin]))
            ->assertNotFound();

        $this->assertModelExists($foreignDirectory);
        $this->assertModelExists($foreignLogin);
    }

    public function test_saving_an_error_page_writes_it_as_the_account_and_applies_the_website_with_its_aliases()
    {
        $alias = Domain::factory()->pointerOf($this->domain)->create(['name' => 'example.net']);
        Domain::factory()->pointerOf($this->domain, PointerMode::Redirect)->create(['name' => 'example.org']);
        $agent = $this->app->instance(AgentClient::class, new FakeAgentClient);

        $this->actingAs($this->account)
            ->put(route('domains.error-pages.update', [$this->domain, 404]), ['content' => '<h1>Hilang</h1>'])
            ->assertSessionHasNoErrors();

        $this->assertSame([
            ['method' => 'fs.mkdir', 'params' => ['username' => 'budi', 'path' => 'domains/example.com/public_html/.errors']],
            ['method' => 'fs.write', 'params' => ['username' => 'budi', 'path' => 'domains/example.com/public_html/.errors/404.html', 'content' => '<h1>Hilang</h1>', 'version' => '']],
        ], $agent->calls());

        $this->assertSame([404], $this->domain->fresh()->error_pages);
        $this->assertSame(DomainStatus::Pending, $this->domain->fresh()->status);
        $this->assertSame(DomainStatus::Pending, $alias->fresh()->status);

        // The alias shows the same folder, so it shows the same pages; the
        // redirecting pointer serves nothing.
        $payloads = Task::query()->where('action', 'web.apply_domain')->orderBy('id')->pluck('payload');
        $this->assertSame(['example.com', 'example.net'], $payloads->pluck('domain')->all());
        $this->assertSame([404], $payloads[0]['error_pages']);
        $this->assertSame([404], $payloads[1]['error_pages']);

        $this->assertDatabaseHas(AuditLog::class, ['action' => 'domain.error_page_saved', 'subject_id' => $this->domain->id]);
    }

    public function test_saving_a_page_that_is_on_already_only_rewrites_it()
    {
        $this->domain->forceFill(['error_pages' => [404, 503]])->save();
        $this->app->instance(AgentClient::class, new FakeAgentClient);

        $this->actingAs($this->account)
            ->put(route('domains.error-pages.update', [$this->domain, 503]), ['content' => '<h1>Sebentar</h1>'])
            ->assertSessionHasNoErrors();

        $this->assertSame([404, 503], $this->domain->fresh()->error_pages);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_an_error_page_that_cannot_be_written_is_not_turned_on()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('call')->twice()
            ->andReturnUsing(fn (string $method) => $method === 'fs.mkdir'
                ? throw new AgentException('exists', -32000, reason: 'exists')
                : throw new AgentException('full', -32000, reason: 'no_space')));

        $this->actingAs($this->account)
            ->put(route('domains.error-pages.update', [$this->domain, 404]), ['content' => '<h1>x</h1>'])
            ->assertSessionHasErrors(['content' => 'The disk quota of the account is full.']);

        $this->assertNull($this->domain->fresh()->error_pages);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_only_the_listed_statuses_can_have_a_page()
    {
        $this->actingAs($this->account)
            ->put('/domains/'.$this->domain->id.'/error-pages/418', ['content' => 'x'])
            ->assertNotFound();
    }

    public function test_a_suspended_account_cannot_write_an_error_page()
    {
        $this->account->forceFill(['status' => AccountStatus::Suspended])->save();
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('call'));

        $this->actingAs($this->account)
            ->put(route('domains.error-pages.update', [$this->domain, 404]), ['content' => '<h1>x</h1>'])
            ->assertSessionHasErrors();

        $this->assertNull($this->domain->fresh()->error_pages);
    }

    public function test_removing_an_error_page_shows_the_servers_page_again_and_keeps_the_file()
    {
        $this->domain->forceFill(['error_pages' => [404, 503]])->save();
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('call'));

        $this->actingAs($this->account)
            ->delete(route('domains.error-pages.destroy', [$this->domain, 404]))
            ->assertSessionHasNoErrors();

        $this->assertSame([503], $this->domain->fresh()->error_pages);
        $this->assertSame([503], Task::query()->sole()->payload['error_pages']);

        $this->actingAs($this->account)
            ->delete(route('domains.error-pages.destroy', [$this->domain, 503]))
            ->assertSessionHasNoErrors();

        $this->assertNull($this->domain->fresh()->error_pages);
        $this->assertArrayNotHasKey('error_pages', Task::query()->latest('id')->first()->payload);
    }

    public function test_a_folder_is_protected_with_its_logins_for_the_website_and_its_aliases()
    {
        Domain::factory()->pointerOf($this->domain)->create(['name' => 'example.net']);

        $this->actingAs($this->account)
            ->post(route('domains.protected-directories.store', $this->domain), ['path' => ' admin/files/ ', 'realm' => 'Area admin'])
            ->assertSessionHasNoErrors();

        $directory = ProtectedDirectory::query()->sole();
        $this->assertSame('/admin/files', $directory->path);

        $this->actingAs($this->account)
            ->post(route('domains.protected-directories.logins.store', [$this->domain, $directory]), [
                'username' => 'siti',
                'password' => 'a-long-password-1',
            ])
            ->assertSessionHasNoErrors();

        $login = ProtectedDirectoryUser::query()->sole();
        $this->assertTrue(CryptPassword::matches('a-long-password-1', $login->password_hash));
        $this->assertStringStartsWith('$6$', $login->password_hash);
        $this->assertArrayNotHasKey('password_hash', $login->toArray());

        $payloads = Task::query()->where('action', 'web.apply_domain')->orderBy('id')->get()->pluck('payload');
        $expected = [['path' => '/admin/files', 'realm' => 'Area admin', 'users' => [['name' => 'siti', 'hash' => $login->password_hash]]]];

        // The last two tasks applied the website and its alias with the login.
        $this->assertSame(['example.com', 'example.net'], $payloads->slice(-2)->pluck('domain')->values()->all());
        $this->assertSame($expected, $payloads[2]['protected_directories']);
        $this->assertSame($expected, $payloads[3]['protected_directories']);

        $this->assertDatabaseHas(AuditLog::class, ['action' => 'protected_directory.login_added']);
        $this->assertStringNotContainsString('a-long-password-1', (string) json_encode(AuditLog::query()->pluck('changes')));
    }

    /**
     * @param  array<string, string>  $data
     */
    #[DataProvider('invalidDirectories')]
    public function test_invalid_folders_are_refused(array $data, string $field)
    {
        $this->actingAs($this->account)
            ->post(route('domains.protected-directories.store', $this->domain), [...['path' => '/admin', 'realm' => 'Admin'], ...$data])
            ->assertSessionHasErrors($field);

        $this->assertDatabaseCount('protected_directories', 0);
        $this->assertDatabaseCount('tasks', 0);
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function invalidDirectories(): array
    {
        return [
            'parent folder' => [['path' => '/../etc'], 'path'],
            'hidden folder' => [['path' => '/.git'], 'path'],
            'space' => [['path' => '/a b'], 'path'],
            'double slash' => [['path' => '/a//b'], 'path'],
            'nginx syntax' => [['path' => '/a;b'], 'path'],
            'quote in the prompt' => [['realm' => 'x" allow all'], 'realm'],
            'variable in the prompt' => [['realm' => 'x $host'], 'realm'],
            'prompt too long' => [['realm' => str_repeat('x', 65)], 'realm'],
        ];
    }

    public function test_a_folder_can_be_protected_once_and_up_to_the_limit()
    {
        ProtectedDirectory::factory()->for($this->domain)->create(['path' => '/admin']);

        $this->actingAs($this->account)
            ->post(route('domains.protected-directories.store', $this->domain), ['path' => '/admin', 'realm' => 'x'])
            ->assertSessionHasErrors(['path' => 'This folder is protected already.']);

        ProtectedDirectory::factory()->for($this->domain)->count(WebSettings::MAX_PROTECTED_DIRECTORIES - 1)->create();

        $this->actingAs($this->account)
            ->post(route('domains.protected-directories.store', $this->domain), ['path' => '/one-more', 'realm' => 'x'])
            ->assertSessionHasErrors('path');

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_logins_need_a_valid_unique_name_and_a_long_password()
    {
        $directory = ProtectedDirectory::factory()->for($this->domain)->create();
        ProtectedDirectoryUser::factory()->for($directory)->create(['username' => 'siti']);
        $store = route('domains.protected-directories.logins.store', [$this->domain, $directory]);

        $this->actingAs($this->account)->post($store, ['username' => 'siti', 'password' => 'a-long-password-1'])
            ->assertSessionHasErrors(['username' => 'This folder already has a login with this name.']);
        $this->actingAs($this->account)->post($store, ['username' => 'a:b', 'password' => 'a-long-password-1'])
            ->assertSessionHasErrors('username');
        $this->actingAs($this->account)->post($store, ['username' => 'budi', 'password' => 'short'])
            ->assertSessionHasErrors('password');

        $this->assertDatabaseCount('protected_directory_users', 1);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_a_login_gets_a_new_password_and_can_be_removed()
    {
        $directory = ProtectedDirectory::factory()->for($this->domain)->create();
        $login = ProtectedDirectoryUser::factory()->for($directory)->create(['username' => 'siti']);

        $this->actingAs($this->account)
            ->put(route('domains.protected-directories.logins.update', [$this->domain, $directory, $login]), ['password' => 'another-password-2'])
            ->assertSessionHasNoErrors();

        $this->assertTrue(CryptPassword::matches('another-password-2', $login->fresh()->password_hash));
        $this->assertSame($login->fresh()->password_hash, Task::query()->sole()->payload['protected_directories'][0]['users'][0]['hash']);

        $this->actingAs($this->account)
            ->delete(route('domains.protected-directories.logins.destroy', [$this->domain, $directory, $login]))
            ->assertSessionHasNoErrors();

        $this->assertModelMissing($login);
        $this->assertSame([], Task::query()->latest('id')->first()->payload['protected_directories'][0]['users']);
    }

    public function test_opening_a_folder_removes_it_with_its_logins()
    {
        $directory = ProtectedDirectory::factory()->for($this->domain)->create();
        $login = ProtectedDirectoryUser::factory()->for($directory)->create();

        $this->actingAs($this->account)
            ->delete(route('domains.protected-directories.destroy', [$this->domain, $directory]))
            ->assertSessionHasNoErrors();

        $this->assertModelMissing($directory);
        $this->assertModelMissing($login);
        $this->assertArrayNotHasKey('protected_directories', Task::query()->sole()->payload);
    }

    public function test_hotlink_protection_is_turned_on_changed_and_off()
    {
        $this->actingAs($this->account)
            ->put(route('domains.hotlink.update', $this->domain), [
                'enabled' => true,
                'extensions' => ['png', 'jpg'],
                'domains' => "Partner.com\nwww.cdn.net, partner.com",
                'allow_direct' => false,
                'redirect_to' => 'cdn.example.net/no.png',
            ])
            ->assertSessionHasNoErrors();

        $expected = [
            'extensions' => ['jpg', 'png'],
            'domains' => ['partner.com', 'www.cdn.net'],
            'allow_direct' => false,
            'redirect_to' => 'https://cdn.example.net/no.png',
        ];
        $this->assertSame($expected, $this->domain->fresh()->hotlink);
        $this->assertSame($expected, Task::query()->sole()->payload['hotlink']);

        $this->actingAs($this->account)
            ->put(route('domains.hotlink.update', $this->domain), ['enabled' => false, 'allow_direct' => true])
            ->assertSessionHasNoErrors();

        $this->assertNull($this->domain->fresh()->hotlink);
        $this->assertArrayNotHasKey('hotlink', Task::query()->latest('id')->first()->payload);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'domain.hotlink_changed']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('invalidHotlinks')]
    public function test_invalid_hotlink_settings_are_refused(array $data, string $field)
    {
        $this->actingAs($this->account)
            ->put(route('domains.hotlink.update', $this->domain), [...['enabled' => true, 'extensions' => ['png'], 'allow_direct' => true], ...$data])
            ->assertSessionHasErrors($field);

        $this->assertNull($this->domain->fresh()->hotlink);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidHotlinks(): array
    {
        return [
            'no files' => [['extensions' => []], 'extensions'],
            'a script' => [['extensions' => ['php']], 'extensions.0'],
            'a bad domain' => [['domains' => 'partner.com; return 200'], 'domains.0'],
            'a redirect into the website' => [['redirect_to' => 'https://www.example.com/no.png'], 'redirect_to'],
            'a redirect with nginx syntax' => [['redirect_to' => 'https://cdn.example.net/$host'], 'redirect_to'],
        ];
    }

    public function test_content_types_are_stored_by_extension()
    {
        $this->actingAs($this->account)
            ->put(route('domains.mime-types.update', $this->domain), ['mime_types' => [
                ['extension' => '.WEBMANIFEST', 'type' => 'Application/Manifest+JSON'],
                ['extension' => 'foo', 'type' => 'application/x-foo'],
            ]])
            ->assertSessionHasNoErrors();

        $expected = [
            ['extension' => 'foo', 'type' => 'application/x-foo'],
            ['extension' => 'webmanifest', 'type' => 'application/manifest+json'],
        ];
        $this->assertSame($expected, $this->domain->fresh()->mime_types);
        $this->assertSame($expected, Task::query()->sole()->payload['mime_types']);

        $this->actingAs($this->account)
            ->put(route('domains.mime-types.update', $this->domain), ['mime_types' => []])
            ->assertSessionHasNoErrors();

        $this->assertNull($this->domain->fresh()->mime_types);
    }

    public function test_invalid_content_types_are_refused()
    {
        $update = route('domains.mime-types.update', $this->domain);

        $this->actingAs($this->account)
            ->put($update, ['mime_types' => [['extension' => 'foo', 'type' => 'a/b'], ['extension' => 'FOO', 'type' => 'a/c']]])
            ->assertSessionHasErrors('mime_types.1.extension');
        $this->actingAs($this->account)
            ->put($update, ['mime_types' => [['extension' => 'foo', 'type' => 'text/plain; charset=utf-8']]])
            ->assertSessionHasErrors('mime_types.0.type');
        $this->actingAs($this->account)
            ->put($update, ['mime_types' => [['extension' => 'f.o', 'type' => 'a/b']]])
            ->assertSessionHasErrors('mime_types.0.extension');

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_more_extensions_can_run_as_php_but_never_static_files()
    {
        $this->actingAs($this->account)
            ->put(route('domains.php-extensions.update', $this->domain), ['php_extensions' => ['.HTML', 'htm']])
            ->assertSessionHasNoErrors();

        $this->assertSame(['htm', 'html'], $this->domain->fresh()->php_extensions);
        $this->assertSame(['htm', 'html'], Task::query()->sole()->payload['php_extensions']);

        foreach (['txt', 'js', 'php', 'ht.ml'] as $extension) {
            $this->actingAs($this->account)
                ->put(route('domains.php-extensions.update', $this->domain), ['php_extensions' => [$extension]])
                ->assertSessionHasErrors('php_extensions.0');
        }

        $this->assertSame(['htm', 'html'], $this->domain->fresh()->php_extensions);
    }

    public function test_a_website_being_deleted_or_changing_its_certificate_or_a_pointer_cannot_be_changed()
    {
        $deleting = Domain::factory()->for($this->account, 'account')->deleting()->create();
        $sslBusy = Domain::factory()->for($this->account, 'account')->sslPending()->create();
        $pointer = Domain::factory()->pointerOf($this->domain)->create();

        foreach ([$deleting, $sslBusy, $pointer] as $domain) {
            $this->actingAs($this->account)
                ->put(route('domains.php-extensions.update', $domain), ['php_extensions' => ['html']])
                ->assertSessionHasErrors('php_extensions');
        }

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_a_plain_website_is_applied_without_web_settings()
    {
        $this->actingAs($this->account)
            ->put(route('domains.php-extensions.update', $this->domain), ['php_extensions' => []])
            ->assertSessionHasNoErrors();

        // Nothing changed, so nothing was queued.
        $this->assertDatabaseCount('tasks', 0);

        app(DomainService::class)->reapply($this->domain, $this->account);

        $this->assertSame(
            ['username', 'domain', 'php_version', 'ssl', 'force_https'],
            array_keys(Task::query()->sole()->payload),
        );
    }
}
