<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LoginHistoryTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36';

    public function test_admins_see_every_login_newest_first_with_attempts_on_unknown_names()
    {
        $admin = User::factory()->admin()->create();
        $budi = User::factory()->create(['username' => 'budi']);

        $this->login($budi, 'auth.login', '203.0.113.7', now()->subHours(2), self::CHROME);
        $this->login(null, 'auth.failed', '198.51.100.9', now()->subHour(), 'curl/8.5.0', attempted: 'ghost');
        $this->login($budi, 'auth.failed', '198.51.100.9', now(), attempted: 'budi');
        (new AuditLog)->forceFill(['action' => 'domain.created', 'subject_type' => $budi->getMorphClass(), 'subject_id' => $budi->id])->save();

        $this->actingAs($admin)
            ->get(route('admin.login-history.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/login-history/index')
                ->where('scope', 'admin')
                ->has('logins.data', 3)
                ->where('logins.data.0.username', 'budi')
                ->where('logins.data.0.succeeded', false)
                ->where('logins.data.0.attempted', null)
                ->where('logins.data.1.username', null)
                ->where('logins.data.1.attempted', 'ghost')
                ->where('logins.data.1.browser', 'curl')
                ->where('logins.data.2.succeeded', true)
                ->where('logins.data.2.ip_address', '203.0.113.7')
                ->where('logins.data.2.browser', 'Chrome')
                ->where('logins.data.2.os', 'macOS')
                ->missing('logins.data.0.user_agent')
                ->where('filters', ['search' => '', 'result' => '']));
    }

    public function test_resellers_see_only_the_logins_of_their_own_accounts()
    {
        $reseller = User::factory()->reseller()->create();
        $own = User::factory()->for($reseller, 'owner')->create(['username' => 'budi']);
        $foreign = User::factory()->for(User::factory()->reseller(), 'owner')->create(['username' => 'sari']);

        $this->login($own, 'auth.login', '203.0.113.7', now()->subHour());
        $this->login($foreign, 'auth.login', '192.0.2.1', now());
        $this->login($reseller, 'auth.login', '192.0.2.2', now());
        $this->login(null, 'auth.failed', '192.0.2.3', now(), attempted: 'budi2');

        $this->actingAs($reseller)
            ->get(route('reseller.login-history.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('scope', 'reseller')
                ->has('logins.data', 1)
                ->where('logins.data.0.username', 'budi'));

        // Searching cannot reach past its own accounts either.
        foreach (['sari', 'budi2', '192.0.2'] as $search) {
            $this->actingAs($reseller)
                ->get(route('reseller.login-history.index', ['search' => $search]))
                ->assertInertia(fn (Assert $page) => $page->has('logins.data', 0));
        }
    }

    public function test_logins_are_filtered_by_result_username_attempted_name_or_ip_address()
    {
        $admin = User::factory()->admin()->create();
        $budi = User::factory()->create(['username' => 'budi']);
        $sari = User::factory()->create(['username' => 'sari']);

        $this->login($budi, 'auth.login', '203.0.113.7', now()->subHours(3));
        $this->login($budi, 'auth.failed', '198.51.100.9', now()->subHours(2), attempted: 'budi');
        $this->login($sari, 'auth.login', '192.0.2.1', now()->subHour());
        $this->login(null, 'auth.failed', '192.0.2.50', now(), attempted: 'admin-test');

        $cases = [
            [['result' => 'failed'], ['192.0.2.50', '198.51.100.9']],
            [['result' => 'succeeded'], ['192.0.2.1', '203.0.113.7']],
            [['search' => 'bud'], ['198.51.100.9', '203.0.113.7']],
            [['search' => 'admin-te'], ['192.0.2.50']],
            [['search' => '192.0.2.'], ['192.0.2.50', '192.0.2.1']],
            [['search' => 'budi', 'result' => 'succeeded'], ['203.0.113.7']],
        ];

        foreach ($cases as [$filters, $addresses]) {
            $this->actingAs($admin)
                ->get(route('admin.login-history.index', $filters))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('logins.data', fn ($rows) => collect($rows)->pluck('ip_address')->all() === $addresses));
        }
    }

    public function test_an_unknown_result_filter_is_refused()
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.login-history.index', ['result' => 'everything']))
            ->assertSessionHasErrors('result');
    }

    public function test_hosting_accounts_cannot_see_the_login_history_of_others()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.login-history.index'))->assertForbidden();
        $this->actingAs($user)->get(route('reseller.login-history.index'))->assertForbidden();
    }

    private function login(?User $user, string $action, string $ip, mixed $at, ?string $userAgent = null, ?string $attempted = null): void
    {
        (new AuditLog)->forceFill([
            'action' => $action,
            'subject_type' => $user?->getMorphClass(),
            'subject_id' => $user?->id,
            'actor_id' => $action === 'auth.login' ? $user?->id : null,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'changes' => $attempted === null ? null : ['username' => $attempted],
            'created_at' => $at,
        ])->save();
    }
}
