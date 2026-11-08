<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Enums\DomainType;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Package;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SubdomainManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_accounts_can_add_a_subdomain_with_a_folder_of_its_own()
    {
        $account = User::factory()->create(['username' => 'budi']);
        $parent = Domain::factory()->for($account, 'account')->create(['name' => 'example.com']);

        $this->actingAs($account)
            ->post(route('subdomains.store'), ['domain_id' => $parent->id, 'name' => ' Blog '])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('domains.index'));

        $subdomain = Domain::query()->where('type', DomainType::Subdomain)->sole();

        $this->assertSame('blog.example.com', $subdomain->name);
        $this->assertTrue($subdomain->parent->is($parent));
        $this->assertSame($account->id, $subdomain->user_id);
        $this->assertSame(DomainStatus::Pending, $subdomain->status);

        // To the agent a subdomain is a website like a domain.
        $this->assertSame([
            'username' => 'budi',
            'domain' => 'blog.example.com',
            'php_version' => '8.4',
            'ssl' => false,
            'force_https' => false,
        ], Task::query()->where('action', 'web.apply_domain')->sole()->payload);

        $this->assertDatabaseHas(AuditLog::class, [
            'action' => 'subdomain.created',
            'actor_id' => $account->id,
            'subject_id' => $subdomain->id,
        ]);
    }

    public function test_the_domains_page_lists_subdomains_and_pointers_under_their_domain()
    {
        $account = User::factory()->create();
        $zeta = Domain::factory()->for($account, 'account')->create(['name' => 'zeta.com']);
        $alpha = Domain::factory()->for($account, 'account')->primary()->create(['name' => 'alpha.com']);
        Domain::factory()->pointerOf($zeta)->create(['name' => 'zeta.net']);
        Domain::factory()->subdomainOf($zeta, 'shop')->create();
        Domain::factory()->subdomainOf($alpha, 'blog')->create();

        $this->actingAs($account)
            ->get(route('domains.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('domains', 5)
                ->where('domains.0.name', 'alpha.com')
                ->where('domains.0.children_count', 1)
                ->where('domains.1.name', 'blog.alpha.com')
                ->where('domains.1.type', 'subdomain')
                ->where('domains.1.parent_id', $alpha->id)
                ->where('domains.2.name', 'zeta.com')
                ->where('domains.2.children_count', 2)
                ->where('domains.3.name', 'shop.zeta.com')
                ->has('domains.4', fn (Assert $row) => $row
                    ->where('name', 'zeta.net')
                    ->where('type', 'pointer')
                    ->where('pointer_mode', 'alias')
                    ->where('parent_display_name', 'zeta.com')
                    ->etc()));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidLabels(): array
    {
        return [
            'empty' => ['', 'The name field is required.'],
            'www' => ['www', 'www is served automatically with every domain.'],
            'a full name' => ['blog.example.com', 'Enter only the name of the subdomain, such as "blog", with letters, digits and hyphens.'],
            'an underscore' => ['my_blog', 'Enter only the name of the subdomain, such as "blog", with letters, digits and hyphens.'],
            'a leading hyphen' => ['-blog', 'Enter only the name of the subdomain, such as "blog", with letters, digits and hyphens.'],
            'config syntax' => ['blog;', 'Enter only the name of the subdomain, such as "blog", with letters, digits and hyphens.'],
        ];
    }

    #[DataProvider('invalidLabels')]
    public function test_invalid_subdomain_names_are_rejected(string $label, string $error)
    {
        $account = User::factory()->create();
        $parent = Domain::factory()->for($account, 'account')->create(['name' => 'example.com']);

        $this->actingAs($account)
            ->post(route('subdomains.store'), ['domain_id' => $parent->id, 'name' => $label])
            ->assertSessionHasErrors(['name' => $error]);

        $this->assertDatabaseCount('domains', 1);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_a_subdomain_cannot_be_added_to_another_accounts_domain()
    {
        $account = User::factory()->create();
        $foreign = Domain::factory()->create(['name' => 'other.com']);

        $this->actingAs($account)
            ->post(route('subdomains.store'), ['domain_id' => $foreign->id, 'name' => 'blog'])
            ->assertSessionHasErrors('domain_id');

        $this->assertDatabaseCount('domains', 1);
    }

    public function test_a_subdomain_needs_a_domain_as_its_parent()
    {
        $account = User::factory()->create();
        $parent = Domain::factory()->for($account, 'account')->create(['name' => 'example.com']);
        $subdomain = Domain::factory()->subdomainOf($parent, 'blog')->create();
        $pointer = Domain::factory()->pointerOf($parent)->create(['name' => 'example.net']);

        foreach ([$subdomain, $pointer] as $notADomain) {
            $this->actingAs($account)
                ->post(route('subdomains.store'), ['domain_id' => $notADomain->id, 'name' => 'shop'])
                ->assertSessionHasErrors('domain_id');
        }

        $this->assertDatabaseCount('domains', 3);
    }

    public function test_a_domain_that_is_being_deleted_takes_no_subdomains()
    {
        $account = User::factory()->create();
        $parent = Domain::factory()->for($account, 'account')->deleting()->create(['name' => 'example.com']);

        $this->actingAs($account)
            ->post(route('subdomains.store'), ['domain_id' => $parent->id, 'name' => 'blog'])
            ->assertSessionHasErrors(['domain_id' => 'This domain is being deleted.']);
    }

    public function test_a_subdomain_that_exists_as_a_domain_cannot_be_added_again()
    {
        $account = User::factory()->create();
        $parent = Domain::factory()->for($account, 'account')->create(['name' => 'example.com']);
        Domain::factory()->for($account, 'account')->create(['name' => 'blog.example.com']);

        $this->actingAs($account)
            ->post(route('subdomains.store'), ['domain_id' => $parent->id, 'name' => 'blog'])
            ->assertSessionHasErrors(['name' => 'You have already added this domain.']);
    }

    public function test_subdomains_count_against_the_subdomain_limit_only()
    {
        $account = User::factory()->create([
            'package_id' => Package::factory()->create(['domains' => 1, 'subdomains' => 1])->id,
        ]);
        $parent = Domain::factory()->for($account, 'account')->create(['name' => 'example.com']);

        // The one domain the package allows does not stop the subdomain.
        $this->actingAs($account)
            ->post(route('subdomains.store'), ['domain_id' => $parent->id, 'name' => 'blog'])
            ->assertSessionHasNoErrors();

        $this->actingAs($account)
            ->post(route('subdomains.store'), ['domain_id' => $parent->id, 'name' => 'shop'])
            ->assertSessionHasErrors([
                'name' => 'Your package allows 1 subdomains. Remove a subdomain or ask your provider for a bigger package.',
            ]);

        $this->assertDatabaseCount('domains', 2);
    }
}
