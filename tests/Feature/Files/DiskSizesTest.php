<?php

namespace Tests\Feature\Files;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\TestCase;

class DiskSizesTest extends TestCase
{
    use RefreshDatabase;

    private User $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = User::factory()->create(['username' => 'budi']);
    }

    public function test_the_folder_is_counted_after_the_page_has_loaded()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('call')->once()
            ->with('fs.sizes', ['username' => 'budi', 'path' => 'domains'], 600)
            ->andReturn([
                'size' => 3610,
                'files' => 5,
                'folders' => 3,
                'truncated' => false,
                'entries' => [['name' => 'example.com', 'type' => 'directory', 'size' => 3010, 'files' => 2, 'folders' => 1]],
                'largest' => [['path' => 'domains/example.com/big.zip', 'type' => 'file', 'size' => 3000, 'modified' => 1794363995]],
            ]));

        $this->actingAs($this->account)
            ->get(route('files.sizes', ['path' => 'domains']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('files/sizes')
                ->where('path', 'domains')
                ->missing('sizes')
                ->loadDeferredProps(fn (Assert $reload) => $reload
                    ->where('sizes.error', null)
                    ->where('sizes.result.size', 3610)
                    ->where('sizes.result.entries.0.name', 'example.com')
                    ->where('sizes.result.largest.0', ['path' => 'domains/example.com/big.zip', 'size' => 3000, 'modified' => 1794363995])));
    }

    public function test_a_path_that_is_not_a_folder_says_so()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('call')->once()
            ->andThrow(new AgentException('index.php is not a folder', -32000, reason: 'not_directory')));

        $this->actingAs($this->account)
            ->get(route('files.sizes', ['path' => 'index.php']))
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps(fn (Assert $reload) => $reload
                    ->where('sizes.result', null)
                    ->whereType('sizes.error', 'string')));
    }

    public function test_paths_outside_the_home_go_back_to_the_home()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('call'));

        $this->actingAs($this->account)
            ->get(route('files.sizes', ['path' => '../sari']))
            ->assertRedirect(route('files.sizes'));
    }
}
