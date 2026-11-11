<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Enums\PointerMode;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PhpEnabledTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_turning_php_off_reaches_the_website_and_its_alias()
    {
        $account = User::factory()->create(['username' => 'budi']);
        $domain = Domain::factory()->for($account, 'account')->create(['name' => 'example.com']);
        Domain::factory()->pointerOf($domain)->create(['name' => 'example.net']);
        Domain::factory()->pointerOf($domain, PointerMode::Redirect)->create(['name' => 'example.org']);

        $this->actingAs($account)
            ->from(route('domains.index'))
            ->put(route('domains.php-enabled.update', $domain), ['php_enabled' => false])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'example.com no longer runs PHP; its PHP files are refused.');

        $domain->refresh();
        $this->assertFalse($domain->php_enabled);
        $this->assertSame(DomainStatus::Pending, $domain->status);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'domain.php_disabled', 'actor_id' => $account->id, 'subject_id' => $domain->id]);

        $payloads = Task::query()->where('action', 'web.apply_domain')->get()->keyBy('payload.domain');
        $this->assertTrue($payloads['example.com']->payload['no_php']);
        $this->assertTrue($payloads['example.net']->payload['no_php']);
        $this->assertArrayNotHasKey('no_php', $payloads['example.org']->payload);

        // The page shows the alias without PHP too.
        $this->actingAs($account)
            ->get(route('domains.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('domains.0.php_enabled', false)
                ->where('domains.1.php_enabled', false));
    }

    public function test_turning_php_on_again_sends_the_usual_website()
    {
        $account = User::factory()->create();
        $domain = Domain::factory()->for($account, 'account')->create(['php_enabled' => false]);

        $this->actingAs($account)
            ->put(route('domains.php-enabled.update', $domain), ['php_enabled' => true])
            ->assertSessionHasNoErrors();

        $this->assertTrue($domain->fresh()->php_enabled);
        $this->assertArrayNotHasKey('no_php', Task::query()->where('action', 'web.apply_domain')->sole()->payload);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'domain.php_enabled']);
    }

    public function test_pointers_others_and_bad_values_are_refused()
    {
        $account = User::factory()->create();
        $domain = Domain::factory()->for($account, 'account')->create();
        $alias = Domain::factory()->pointerOf($domain)->create();

        $this->actingAs($account)
            ->put(route('domains.php-enabled.update', $alias), ['php_enabled' => false])
            ->assertSessionHasErrors(['php_enabled' => 'A pointer runs PHP like its domain.']);

        $this->actingAs(User::factory()->create())
            ->put(route('domains.php-enabled.update', $domain), ['php_enabled' => false])
            ->assertNotFound();

        $this->actingAs($account)
            ->put(route('domains.php-enabled.update', $domain), ['php_enabled' => 'maybe'])
            ->assertSessionHasErrors('php_enabled');

        $this->assertTrue($domain->fresh()->php_enabled);
        $this->assertDatabaseCount('tasks', 0);
    }
}
