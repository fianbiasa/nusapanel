<?php

namespace Tests\Feature\Redirects;

use App\Enums\PointerMode;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Task;
use App\Models\UrlRedirect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UrlRedirectManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_admins_cannot_reach_redirects()
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('redirects.index'))
            ->assertForbidden();
    }

    public function test_the_page_lists_the_accounts_redirects_and_the_websites_that_can_take_one()
    {
        $account = User::factory()->create();
        $domain = Domain::factory()->for($account, 'account')->sslActive()->create(['name' => 'example.com']);
        Domain::factory()->for($account, 'account')->pending()->create(['name' => 'pending.com']);
        Domain::factory()->pointerOf($domain, PointerMode::Redirect)->create(['name' => 'example.net']);
        $alias = Domain::factory()->pointerOf($domain)->create(['name' => 'example.org']);
        UrlRedirect::factory()->for($domain)->wildcard()->create(['path' => '/promo', 'destination' => 'https://shop.example.com/sale']);
        UrlRedirect::factory()->for(Domain::factory()->create())->create();

        $this->actingAs($account)
            ->get(route('redirects.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('redirects/index')
                ->has('redirects', 1)
                ->has('redirects.0', fn (Assert $row) => $row
                    ->where('domain', 'example.com')
                    ->where('path', '/promo')
                    ->where('destination', 'https://shop.example.com/sale')
                    ->where('status_code', 301)
                    ->where('wildcard', true)
                    ->etc())
                // Neither the pending domain nor the redirecting pointer.
                ->where('websites', [
                    ['id' => $domain->id, 'name' => 'example.com', 'display_name' => 'example.com', 'ssl' => true],
                    ['id' => $alias->id, 'name' => 'example.org', 'display_name' => 'example.org', 'ssl' => false],
                ])
                ->where('max_per_website', 100));
    }

    public function test_accounts_can_add_a_redirect_that_the_agent_applies_to_the_website()
    {
        $account = User::factory()->create(['username' => 'budi']);
        $domain = Domain::factory()->for($account, 'account')->create(['name' => 'example.com']);
        UrlRedirect::factory()->for($domain)->temporary()->create(['path' => '/zz', 'destination' => 'https://example.org/zz']);

        $this->actingAs($account)
            ->post(route('redirects.store'), [
                'domain_id' => $domain->id,
                'path' => 'promo/',
                'destination' => ' Shop.Example.COM/Sale?ref=a ',
                'status_code' => '301',
                'wildcard' => '0',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('redirects.index'));

        $redirect = UrlRedirect::query()->where('path', '/promo')->sole();

        $this->assertSame('https://shop.example.com/Sale?ref=a', $redirect->destination);
        $this->assertSame(301, $redirect->status_code);
        $this->assertFalse($redirect->wildcard);

        // Every redirect of the website, by path.
        $this->assertSame([
            'username' => 'budi',
            'domain' => 'example.com',
            'php_version' => '8.4',
            'ssl' => false,
            'force_https' => false,
            'redirects' => [
                ['path' => '/promo', 'destination' => 'https://shop.example.com/Sale?ref=a', 'status' => 301, 'wildcard' => false],
                ['path' => '/zz', 'destination' => 'https://example.org/zz', 'status' => 302, 'wildcard' => false],
            ],
        ], Task::query()->where('action', 'web.apply_domain')->sole()->payload);

        $this->assertDatabaseHas(AuditLog::class, [
            'action' => 'redirect.created',
            'actor_id' => $account->id,
            'subject_id' => $redirect->id,
        ]);
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function invalidRedirects(): array
    {
        return [
            'path with a space' => [['path' => '/a b'], 'path'],
            'path with config syntax' => [['path' => '/a;return 200'], 'path'],
            'path going up' => [['path' => '/a/../b'], 'path'],
            'path with an encoded character' => [['path' => '/a%20b'], 'path'],
            'destination of another scheme' => [['destination' => 'ftp://example.org/'], 'destination'],
            'destination with a script' => [['destination' => 'javascript:alert(1)'], 'destination'],
            'destination with a variable' => [['destination' => 'https://example.org/$host'], 'destination'],
            'destination with a quote' => [['destination' => 'https://example.org/"'], 'destination'],
            'destination with a password' => [['destination' => 'https://user:secret@example.org/'], 'destination'],
            'destination without a host' => [['destination' => 'https://'], 'destination'],
            'wildcard with a query' => [['destination' => 'https://example.org/?a=b', 'wildcard' => '1'], 'destination'],
            'status 307' => [['status_code' => '307'], 'status_code'],
        ];
    }

    /**
     * @param  array<string, string>  $overrides
     */
    #[DataProvider('invalidRedirects')]
    public function test_unsafe_redirects_are_rejected(array $overrides, string $field)
    {
        $account = User::factory()->create();
        $domain = Domain::factory()->for($account, 'account')->create();

        $this->actingAs($account)
            ->post(route('redirects.store'), [
                'domain_id' => $domain->id,
                'path' => '/promo',
                'destination' => 'https://example.org/',
                'status_code' => '301',
                ...$overrides,
            ])
            ->assertSessionHasErrors($field);

        $this->assertDatabaseCount('url_redirects', 0);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_redirects_need_an_active_website_of_the_account_that_takes_them()
    {
        $account = User::factory()->create();
        $domain = Domain::factory()->for($account, 'account')->create();

        $websites = [
            Domain::factory()->create(),
            Domain::factory()->for($account, 'account')->pending()->create(),
            Domain::factory()->pointerOf($domain, PointerMode::Redirect)->create(),
        ];

        foreach ($websites as $website) {
            $this->actingAs($account)
                ->post(route('redirects.store'), [
                    'domain_id' => $website->id,
                    'path' => '/promo',
                    'destination' => 'https://example.org/',
                    'status_code' => '301',
                ])
                ->assertSessionHasErrors('domain_id');
        }

        $this->assertDatabaseCount('url_redirects', 0);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_a_path_takes_one_redirect_and_a_website_at_most_a_hundred()
    {
        $account = User::factory()->create();
        $domain = Domain::factory()->for($account, 'account')->create();
        UrlRedirect::factory()->for($domain)->create(['path' => '/promo']);

        $this->actingAs($account)
            ->post(route('redirects.store'), ['domain_id' => $domain->id, 'path' => '/promo', 'destination' => 'https://example.org/', 'status_code' => '301'])
            ->assertSessionHasErrors(['path' => 'This website already has a redirect for this path.']);

        UrlRedirect::factory()->for($domain)->count(99)->create();

        $this->actingAs($account)
            ->post(route('redirects.store'), ['domain_id' => $domain->id, 'path' => '/last', 'destination' => 'https://example.org/', 'status_code' => '301'])
            ->assertSessionHasErrors(['path' => 'A website can have at most 100 redirects.']);

        $this->assertDatabaseCount('url_redirects', 100);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_accounts_can_remove_a_redirect_and_the_website_is_applied_without_it()
    {
        $account = User::factory()->create(['username' => 'budi']);
        $domain = Domain::factory()->for($account, 'account')->create(['name' => 'example.com']);
        $redirect = UrlRedirect::factory()->for($domain)->create(['path' => '/promo']);

        $this->actingAs($account)
            ->from(route('redirects.index'))
            ->delete(route('redirects.destroy', $redirect))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('redirects.index'));

        $this->assertModelMissing($redirect);
        $this->assertArrayNotHasKey('redirects', Task::query()->where('action', 'web.apply_domain')->sole()->payload);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'redirect.deleted', 'actor_id' => $account->id]);
    }

    public function test_accounts_cannot_remove_another_accounts_redirect()
    {
        $redirect = UrlRedirect::factory()->create();

        $this->actingAs(User::factory()->create())
            ->delete(route('redirects.destroy', $redirect))
            ->assertNotFound();

        $this->assertModelExists($redirect);
        $this->assertDatabaseCount('tasks', 0);
    }
}
