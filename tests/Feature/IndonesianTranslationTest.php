<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The suite runs in English (see phpunit.xml); these tests switch to the
 * panel's production locale to prove its messages are translated.
 */
class IndonesianTranslationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->app->setLocale('id');
    }

    public function test_validation_errors_use_indonesian_messages_and_field_names()
    {
        $user = User::factory()->create(['name' => 'Budi']);

        $this->actingAs($user)
            ->patch(route('profile.update'), ['name' => '', 'email' => $user->email])
            ->assertSessionHasErrors(['name' => 'Kolom nama wajib diisi.']);

        $this->assertSame('Budi', $user->fresh()->name);
    }

    public function test_domain_conflicts_are_reported_in_indonesian()
    {
        Domain::factory()->create(['name' => 'example.com']);

        $this->actingAs(User::factory()->create())
            ->post(route('domains.store'), ['domain' => 'example.com'])
            ->assertSessionHasErrors(['domain' => 'Domain ini sudah digunakan oleh akun lain.']);

        $this->assertDatabaseCount('domains', 1);
        Queue::assertNothingPushed();
    }
}
