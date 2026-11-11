<?php

namespace Tests\Feature\Domains;

use App\Enums\PointerMode;
use App\Enums\WwwMode;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WwwModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_an_account_chooses_which_name_its_visitors_end_up_on()
    {
        $account = User::factory()->create(['username' => 'budi']);
        $domain = Domain::factory()->for($account, 'account')->create(['name' => 'example.com']);

        $this->actingAs($account)
            ->get(route('domains.index'))
            ->assertInertia(fn (Assert $page) => $page->where('domains.0.www_mode', 'both'));

        $this->actingAs($account)
            ->from(route('domains.index'))
            ->put(route('domains.www.update', $domain), ['www_mode' => 'www'])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'example.com now always opens as www.example.com.');

        $this->assertSame(WwwMode::Www, $domain->fresh()->www_mode);
        $this->assertSame('www', Task::query()->where('action', 'web.apply_domain')->sole()->payload['www']);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'domain.www_changed', 'actor_id' => $account->id, 'subject_id' => $domain->id]);

        // Serving both names again sends no mode, as before the setting existed.
        $this->actingAs($account)
            ->put(route('domains.www.update', $domain), ['www_mode' => 'both'])
            ->assertSessionHasNoErrors();

        $this->assertArrayNotHasKey('www', Task::query()->where('action', 'web.apply_domain')->latest('id')->first()->payload);
    }

    public function test_an_alias_pointer_has_its_own_choice_but_a_redirecting_one_none()
    {
        $account = User::factory()->create();
        $domain = Domain::factory()->for($account, 'account')->create(['name' => 'example.com']);
        $alias = Domain::factory()->pointerOf($domain)->create(['name' => 'example.net']);
        $forward = Domain::factory()->pointerOf($domain, PointerMode::Redirect)->create(['name' => 'example.org']);

        $this->actingAs($account)
            ->put(route('domains.www.update', $alias), ['www_mode' => 'bare'])
            ->assertSessionHasNoErrors();

        $this->assertSame('bare', Task::query()->where('action', 'web.apply_domain')->sole()->payload['www']);

        $this->actingAs($account)
            ->put(route('domains.www.update', $forward), ['www_mode' => 'www'])
            ->assertSessionHasErrors(['www_mode' => 'A redirecting pointer sends every visitor to its domain already.']);

        $this->assertSame(WwwMode::Both, $forward->fresh()->www_mode);
    }

    public function test_only_the_owner_may_choose_and_only_known_modes()
    {
        $domain = Domain::factory()->create();

        $this->actingAs(User::factory()->create())
            ->put(route('domains.www.update', $domain), ['www_mode' => 'www'])
            ->assertNotFound();

        $this->actingAs($domain->account)
            ->put(route('domains.www.update', $domain), ['www_mode' => 'sometimes'])
            ->assertSessionHasErrors('www_mode');

        $this->assertSame(WwwMode::Both, $domain->fresh()->www_mode);
        $this->assertDatabaseCount('tasks', 0);
    }
}
