<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Enums\PointerMode;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Task;
use App\Models\User;
use App\Support\PhpSettings;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PhpSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_the_domains_page_shows_each_websites_php_settings_and_the_choices()
    {
        $account = User::factory()->create();
        Domain::factory()->for($account, 'account')->create([
            'name' => 'example.com',
            'php_settings' => ['memory_limit' => '512M', 'date.timezone' => 'Asia/Jakarta'],
        ]);

        $this->actingAs($account)
            ->get(route('domains.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('domains.0.php_settings', [
                    ...PhpSettings::DEFAULTS,
                    'memory_limit' => '512M',
                    'date.timezone' => 'Asia/Jakarta',
                ])
                ->where('domains.0.php_settings_changed', true)
                ->where('php_setting_options.defaults', PhpSettings::DEFAULTS)
                ->where('php_setting_options.memory_limits', PhpSettings::MEMORY_LIMITS)
                ->where('php_setting_options.sizes', PhpSettings::SIZES)
                ->has('php_setting_options.timezones', count(DateTimeZone::listIdentifiers())));
    }

    public function test_accounts_can_change_the_php_settings_of_a_website()
    {
        $account = User::factory()->create(['username' => 'budi']);
        $domain = Domain::factory()->for($account, 'account')->create(['name' => 'example.com']);
        $alias = Domain::factory()->pointerOf($domain)->create(['name' => 'example.net']);
        Domain::factory()->pointerOf($domain, PointerMode::Redirect)->create(['name' => 'example.org']);
        $subdomain = Domain::factory()->subdomainOf($domain, 'blog')->create();

        $this->actingAs($account)
            ->from(route('domains.index'))
            ->put(route('domains.php-settings.update', $domain), $this->fields([
                'memory_limit' => '512M',
                'upload_max_filesize' => '256M',
                'post_max_size' => '256M',
                'max_input_vars' => '10000',
                'display_errors' => '1',
                'date_timezone' => 'Asia/Jakarta',
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('domains.index'));

        // Only what differs from the defaults is kept.
        $expected = [
            'date.timezone' => 'Asia/Jakarta',
            'display_errors' => 'on',
            'max_input_vars' => '10000',
            'memory_limit' => '512M',
            'post_max_size' => '256M',
            'upload_max_filesize' => '256M',
        ];

        $domain->refresh();
        $this->assertSame($expected, $domain->php_settings);
        $this->assertSame(DomainStatus::Pending, $domain->status);

        // The website, and the alias that runs it, get the settings; the
        // redirecting pointer and the subdomain are left alone.
        $payloads = Task::query()->where('action', 'web.apply_domain')->orderBy('id')->pluck('payload');

        $this->assertSame(['example.com', 'example.net'], $payloads->pluck('domain')->all());
        $this->assertSame($expected, $payloads[0]['php_settings']);
        $this->assertSame($expected, $payloads[1]['php_settings']);
        $this->assertSame('alias', $payloads[1]['kind']);
        $this->assertSame(DomainStatus::Pending, $alias->fresh()->status);
        $this->assertSame(DomainStatus::Active, $subdomain->fresh()->status);

        $this->assertDatabaseHas(AuditLog::class, [
            'action' => 'domain.php_settings_changed',
            'actor_id' => $account->id,
            'subject_id' => $domain->id,
        ]);
    }

    public function test_going_back_to_the_defaults_forgets_the_settings()
    {
        $account = User::factory()->create();
        $domain = Domain::factory()->for($account, 'account')->create(['php_settings' => ['memory_limit' => '512M']]);

        $this->actingAs($account)
            ->put(route('domains.php-settings.update', $domain), $this->fields())
            ->assertSessionHasNoErrors();

        $this->assertNull($domain->fresh()->php_settings);
        $this->assertArrayNotHasKey('php_settings', Task::query()->sole()->payload);
    }

    public function test_saving_the_same_settings_changes_nothing()
    {
        $account = User::factory()->create();
        $domain = Domain::factory()->for($account, 'account')->create(['php_settings' => ['memory_limit' => '512M']]);

        $this->actingAs($account)
            ->put(route('domains.php-settings.update', $domain), $this->fields(['memory_limit' => '512M']))
            ->assertSessionHasNoErrors();

        $this->assertSame(DomainStatus::Active, $domain->fresh()->status);
        $this->assertDatabaseCount('tasks', 0);
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function invalidSettings(): array
    {
        return [
            'memory in gigabytes' => [['memory_limit' => '1G'], 'memory_limit'],
            'memory not offered' => [['memory_limit' => '300M'], 'memory_limit'],
            'upload over 1 GiB' => [['upload_max_filesize' => '2048M'], 'upload_max_filesize'],
            'post below upload' => [['upload_max_filesize' => '256M', 'post_max_size' => '128M'], 'post_max_size'],
            'no time limit' => [['max_execution_time' => '0'], 'max_execution_time'],
            'over 300 seconds' => [['max_input_time' => '301'], 'max_input_time'],
            'too few input vars' => [['max_input_vars' => '500'], 'max_input_vars'],
            'display errors yes' => [['display_errors' => 'yes'], 'display_errors'],
            'unknown time zone' => [['date_timezone' => 'Asia/Atlantis'], 'date_timezone'],
            'config in a time zone' => [['date_timezone' => "Asia/Jakarta\nphp_admin_value[open_basedir] = /"], 'date_timezone'],
        ];
    }

    /**
     * @param  array<string, string>  $overrides
     */
    #[DataProvider('invalidSettings')]
    public function test_invalid_settings_are_refused(array $overrides, string $field)
    {
        $account = User::factory()->create();
        $domain = Domain::factory()->for($account, 'account')->create();

        $this->actingAs($account)
            ->put(route('domains.php-settings.update', $domain), $this->fields($overrides))
            ->assertSessionHasErrors($field);

        $this->assertNull($domain->fresh()->php_settings);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_pointers_busy_websites_and_other_accounts_websites_keep_their_settings()
    {
        $account = User::factory()->create();
        $domain = Domain::factory()->for($account, 'account')->create();

        foreach ([
            Domain::factory()->pointerOf($domain)->create(),
            Domain::factory()->for($account, 'account')->pending()->create(),
            Domain::factory()->for($account, 'account')->sslPending()->create(),
        ] as $website) {
            $this->actingAs($account)
                ->put(route('domains.php-settings.update', $website), $this->fields(['memory_limit' => '512M']))
                ->assertSessionHasErrors('memory_limit');
        }

        $this->actingAs(User::factory()->create())
            ->put(route('domains.php-settings.update', $domain), $this->fields(['memory_limit' => '512M']))
            ->assertNotFound();

        $this->assertNull($domain->fresh()->php_settings);
        $this->assertDatabaseCount('tasks', 0);
    }

    /**
     * The form as sent: every field, with the defaults unless overridden.
     *
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function fields(array $overrides = []): array
    {
        return [
            'memory_limit' => '256M',
            'upload_max_filesize' => '64M',
            'post_max_size' => '64M',
            'max_execution_time' => '120',
            'max_input_time' => '120',
            'max_input_vars' => '3000',
            'display_errors' => '0',
            'date_timezone' => '',
            ...$overrides,
        ];
    }
}
