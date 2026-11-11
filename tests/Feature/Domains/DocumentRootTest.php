<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DocumentRootTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_a_subdomain_can_be_served_from_a_folder_of_its_domain()
    {
        $account = User::factory()->create(['username' => 'budi']);
        $domain = Domain::factory()->for($account, 'account')->create(['name' => 'example.com']);
        $blog = Domain::factory()->subdomainOf($domain, 'blog')->create();

        $this->actingAs($account)
            ->from(route('domains.index'))
            ->put(route('domains.document-root.update', $blog), ['document_root' => ' /domains/example.com/public_html/blog/ '])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'blog.example.com is now served from domains/example.com/public_html/blog.');

        $blog->refresh();
        $this->assertSame('domains/example.com/public_html/blog', $blog->document_root);
        $this->assertSame(DomainStatus::Pending, $blog->status);
        $this->assertSame('domains/example.com/public_html/blog', Task::query()->where('action', 'web.apply_domain')->sole()->payload['root']);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'domain.root_changed', 'actor_id' => $account->id, 'subject_id' => $blog->id]);

        $this->actingAs($account)
            ->get(route('domains.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('domains.1.document_root', 'domains/example.com/public_html/blog')
                ->where('domains.1.document_root_custom', true));

        // Its own folder again stores nothing, so the agent gets its default.
        $blog->forceFill(['status' => DomainStatus::Active])->save();

        $this->actingAs($account)
            ->put(route('domains.document-root.update', $blog), ['document_root' => 'domains/blog.example.com/public_html'])
            ->assertSessionHasNoErrors();

        $this->assertNull($blog->fresh()->document_root);
        $this->assertArrayNotHasKey('root', Task::query()->where('action', 'web.apply_domain')->latest('id')->first()->payload);
    }

    public function test_only_folders_inside_domains_are_accepted()
    {
        $account = User::factory()->create();
        $blog = Domain::factory()->subdomainOf(Domain::factory()->for($account, 'account')->create(), 'blog')->create();

        foreach (['tmp', 'domains', '.trash/old', 'domains/../tmp', 'domains/x/../../tmp', 'backups/site', 'domains/.git', 'domains/my site'] as $root) {
            $this->actingAs($account)
                ->put(route('domains.document-root.update', $blog), ['document_root' => $root])
                ->assertSessionHasErrors('document_root');
        }

        $this->assertNull($blog->fresh()->document_root);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_only_a_subdomain_of_ones_own_may_change_folder()
    {
        $account = User::factory()->create();
        $domain = Domain::factory()->for($account, 'account')->create(['name' => 'example.com']);
        $blog = Domain::factory()->subdomainOf($domain, 'blog')->create();

        $this->actingAs($account)
            ->put(route('domains.document-root.update', $domain), ['document_root' => 'domains/example.com/public_html/www'])
            ->assertSessionHasErrors(['document_root' => 'Only a subdomain can be served from another folder.']);

        $this->actingAs(User::factory()->create())
            ->put(route('domains.document-root.update', $blog), ['document_root' => 'domains/example.com/public_html/blog'])
            ->assertNotFound();

        $this->assertDatabaseCount('tasks', 0);
    }
}
