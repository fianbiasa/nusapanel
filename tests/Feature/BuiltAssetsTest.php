<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class BuiltAssetsTest extends TestCase
{
    use RefreshDatabase;

    private string $publicPath;

    protected function setUp(): void
    {
        parent::setUp();

        // A public directory of its own, holding a built front end and no
        // "hot" file, as on a server.
        $this->publicPath = storage_path('framework/testing/public-'.Str::random(8));
        File::ensureDirectoryExists($this->publicPath.'/assets');
        File::put($this->manifestPath(), json_encode([
            'resources/css/app.css' => ['file' => 'app-C55e1f.css', 'src' => 'resources/css/app.css', 'isEntry' => true],
            'resources/js/app.tsx' => ['file' => 'app-D93a2b.js', 'src' => 'resources/js/app.tsx', 'isEntry' => true],
            'resources/js/pages/auth/login.tsx' => ['file' => 'login-B71c4d.js', 'src' => 'resources/js/pages/auth/login.tsx', 'isEntry' => true],
        ]));

        $this->app->usePublicPath($this->publicPath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->publicPath);

        parent::tearDown();
    }

    public function test_pages_load_the_built_files_from_assets()
    {
        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertSee('/assets/app-D93a2b.js', false);
        $response->assertSee('/assets/app-C55e1f.css', false);
        $response->assertSee('/assets/login-B71c4d.js', false);
        $response->assertDontSee('/build/', false);
    }

    public function test_open_pages_reload_after_a_new_build()
    {
        $this->get(route('login'), ['X-Inertia' => 'true', 'X-Inertia-Version' => 'an-older-build'])
            ->assertConflict();

        $this->get(route('login'), ['X-Inertia' => 'true', 'X-Inertia-Version' => md5_file($this->manifestPath())])
            ->assertOk();
    }

    private function manifestPath(): string
    {
        return $this->publicPath.'/assets/manifest.json';
    }
}
