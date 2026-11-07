<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Enums\SslStatus;
use App\Jobs\RunAgentTask;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DomainSslTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_the_domains_page_shows_each_domains_certificate_and_the_servers_ip()
    {
        config(['hosting.public_ip' => '203.0.113.10']);

        $account = User::factory()->create();
        $domain = Domain::factory()->for($account, 'account')->sslActive(forceHttps: true)->create(['name' => 'example.com']);

        $this->actingAs($account)
            ->get(route('domains.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('domains/index')
                ->where('public_ip', '203.0.113.10')
                ->has('domains.0', fn (Assert $row) => $row
                    ->where('ssl_status', 'active')
                    ->where('ssl_expires_at', $domain->ssl_expires_at->toIso8601String())
                    ->where('ssl_error', null)
                    ->where('force_https', true)
                    ->etc()));
    }

    public function test_accounts_can_request_a_certificate_for_an_active_domain()
    {
        $account = User::factory()->create(['username' => 'budi']);
        $domain = Domain::factory()->for($account, 'account')->create(['name' => 'example.com']);

        $this->actingAs($account)
            ->from(route('domains.index'))
            ->post(route('domains.ssl.store', $domain))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('domains.index'));

        $this->assertSame(SslStatus::Pending, $domain->fresh()->ssl_status);

        $task = Task::query()->sole();

        $this->assertSame('ssl.issue', $task->action);
        $this->assertSame(['username' => 'budi', 'domain' => 'example.com'], $task->payload);
        $this->assertSame($account->id, $task->user_id);
        $this->assertSame($account->id, $task->actor_id);

        Queue::assertPushed(RunAgentTask::class, fn (RunAgentTask $job) => $job->task->is($task));

        $this->assertDatabaseHas(AuditLog::class, [
            'action' => 'domain.ssl_requested',
            'actor_id' => $account->id,
            'subject_id' => $domain->id,
        ]);
    }

    public function test_a_failed_certificate_request_can_be_retried()
    {
        $account = User::factory()->create();
        $domain = Domain::factory()->for($account, 'account')->sslFailed()->create();

        $this->actingAs($account)
            ->post(route('domains.ssl.store', $domain))
            ->assertSessionHasNoErrors();

        $domain->refresh();

        $this->assertSame(SslStatus::Pending, $domain->ssl_status);
        $this->assertNull($domain->ssl_error);
        $this->assertSame('ssl.issue', Task::query()->sole()->action);
    }

    /**
     * @return array<string, array{DomainStatus}>
     */
    public static function inactiveDomainStatuses(): array
    {
        return [
            'pending' => [DomainStatus::Pending],
            'failed' => [DomainStatus::Failed],
            'deleting' => [DomainStatus::Deleting],
        ];
    }

    #[DataProvider('inactiveDomainStatuses')]
    public function test_certificates_can_only_be_requested_for_active_domains(DomainStatus $status)
    {
        $account = User::factory()->create();
        $domain = Domain::factory()->for($account, 'account')->create(['status' => $status]);

        $this->actingAs($account)
            ->post(route('domains.ssl.store', $domain))
            ->assertSessionHasErrors(['domain' => 'SSL can only be managed for active domains.']);

        $this->assertNull($domain->fresh()->ssl_status);
        $this->assertDatabaseCount('tasks', 0);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function existingCertificateStates(): array
    {
        return [
            'being issued' => ['sslPending'],
            'active' => ['sslActive'],
            'being removed' => ['sslRemoving'],
        ];
    }

    #[DataProvider('existingCertificateStates')]
    public function test_a_certificate_cannot_be_requested_twice(string $state)
    {
        $account = User::factory()->create();
        $domain = Domain::factory()->for($account, 'account')->{$state}()->create();
        $status = $domain->ssl_status;

        $this->actingAs($account)
            ->post(route('domains.ssl.store', $domain))
            ->assertSessionHasErrors(['domain' => 'This domain already has an SSL certificate, or one is being processed.']);

        $this->assertSame($status, $domain->fresh()->ssl_status);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_accounts_can_force_https_while_the_certificate_is_active()
    {
        $account = User::factory()->create(['username' => 'budi']);
        $domain = Domain::factory()->for($account, 'account')->sslActive()->create(['name' => 'example.com']);

        $this->actingAs($account)
            ->put(route('domains.force-https.update', $domain), ['force_https' => true])
            ->assertSessionHasNoErrors();

        $this->assertTrue($domain->fresh()->force_https);

        $task = Task::query()->sole();

        $this->assertSame('web.apply_domain', $task->action);
        $this->assertSame([
            'username' => 'budi',
            'domain' => 'example.com',
            'php_version' => '8.4',
            'ssl' => true,
            'force_https' => true,
        ], $task->payload);
        $this->assertSame($account->id, $task->actor_id);

        $this->assertDatabaseHas(AuditLog::class, [
            'action' => 'domain.https_forced',
            'actor_id' => $account->id,
            'subject_id' => $domain->id,
        ]);
    }

    public function test_accounts_can_stop_forcing_https()
    {
        $account = User::factory()->create();
        $domain = Domain::factory()->for($account, 'account')->sslActive(forceHttps: true)->create();

        $this->actingAs($account)
            ->put(route('domains.force-https.update', $domain), ['force_https' => false])
            ->assertSessionHasNoErrors();

        $this->assertFalse($domain->fresh()->force_https);

        $task = Task::query()->sole();

        $this->assertTrue($task->payload['ssl']);
        $this->assertFalse($task->payload['force_https']);

        $this->assertDatabaseHas(AuditLog::class, [
            'action' => 'domain.https_unforced',
            'subject_id' => $domain->id,
        ]);
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function inactiveCertificateStates(): array
    {
        return [
            'no certificate' => [null],
            'being issued' => ['sslPending'],
            'failed' => ['sslFailed'],
            'being removed' => ['sslRemoving'],
        ];
    }

    #[DataProvider('inactiveCertificateStates')]
    public function test_https_can_only_be_forced_while_the_certificate_is_active(?string $state)
    {
        $account = User::factory()->create();
        $factory = Domain::factory()->for($account, 'account');
        $domain = ($state === null ? $factory : $factory->{$state}())->create();

        $this->actingAs($account)
            ->put(route('domains.force-https.update', $domain), ['force_https' => true])
            ->assertSessionHasErrors(['domain' => 'This domain has no active SSL certificate.']);

        $this->assertFalse($domain->fresh()->force_https);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_https_cannot_be_forced_on_a_domain_that_is_not_active()
    {
        $account = User::factory()->create();
        $domain = Domain::factory()->for($account, 'account')->failed()->sslActive()->create();

        $this->actingAs($account)
            ->put(route('domains.force-https.update', $domain), ['force_https' => true])
            ->assertSessionHasErrors(['domain' => 'SSL can only be managed for active domains.']);

        $this->assertFalse($domain->fresh()->force_https);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_force_https_must_be_a_boolean()
    {
        $account = User::factory()->create();
        $domain = Domain::factory()->for($account, 'account')->sslActive()->create();

        $this->actingAs($account)
            ->put(route('domains.force-https.update', $domain), ['force_https' => 'always'])
            ->assertSessionHasErrors(['force_https' => 'The force https field must be true or false.']);

        $this->actingAs($account)
            ->put(route('domains.force-https.update', $domain))
            ->assertSessionHasErrors(['force_https' => 'The force https field is required.']);

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_accounts_can_remove_an_active_certificate()
    {
        $account = User::factory()->create(['username' => 'budi']);
        $domain = Domain::factory()->for($account, 'account')->sslActive(forceHttps: true)->create(['name' => 'example.com']);

        $this->actingAs($account)
            ->from(route('domains.index'))
            ->delete(route('domains.ssl.destroy', $domain))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('domains.index'));

        $domain->refresh();

        $this->assertSame(SslStatus::Removing, $domain->ssl_status);
        $this->assertFalse($domain->force_https);

        // The website stops using the certificate first; the agent refuses to delete one in use.
        $task = Task::query()->sole();

        $this->assertSame('web.apply_domain', $task->action);
        $this->assertSame([
            'username' => 'budi',
            'domain' => 'example.com',
            'php_version' => '8.4',
            'ssl' => false,
            'force_https' => false,
        ], $task->payload);

        $this->assertDatabaseHas(AuditLog::class, [
            'action' => 'domain.ssl_removed',
            'actor_id' => $account->id,
            'subject_id' => $domain->id,
        ]);
    }

    public function test_a_failed_certificate_request_can_be_cancelled_without_the_agent()
    {
        $account = User::factory()->create();
        $domain = Domain::factory()->for($account, 'account')->sslFailed()->create();

        $this->actingAs($account)
            ->delete(route('domains.ssl.destroy', $domain))
            ->assertSessionHasNoErrors();

        $domain->refresh();

        $this->assertNull($domain->ssl_status);
        $this->assertNull($domain->ssl_error);
        $this->assertDatabaseCount('tasks', 0);

        $this->assertDatabaseHas(AuditLog::class, [
            'action' => 'domain.ssl_cancelled',
            'subject_id' => $domain->id,
        ]);
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function unremovableCertificateStates(): array
    {
        return [
            'no certificate' => [null],
            'being issued' => ['sslPending'],
            'being removed' => ['sslRemoving'],
        ];
    }

    #[DataProvider('unremovableCertificateStates')]
    public function test_only_active_certificates_can_be_removed(?string $state)
    {
        $account = User::factory()->create();
        $factory = Domain::factory()->for($account, 'account');
        $domain = ($state === null ? $factory : $factory->{$state}())->create();
        $status = $domain->ssl_status;

        $this->actingAs($account)
            ->delete(route('domains.ssl.destroy', $domain))
            ->assertSessionHasErrors(['domain' => 'This domain has no active SSL certificate.']);

        $this->assertSame($status, $domain->fresh()->ssl_status);
        $this->assertDatabaseCount('tasks', 0);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function busyCertificateStates(): array
    {
        return [
            'being issued' => ['sslPending'],
            'being removed' => ['sslRemoving'],
        ];
    }

    #[DataProvider('busyCertificateStates')]
    public function test_domains_cannot_be_deleted_while_their_certificate_is_being_processed(string $state)
    {
        $account = User::factory()->create();
        $domain = Domain::factory()->for($account, 'account')->{$state}()->create();

        $this->actingAs($account)
            ->delete(route('domains.destroy', $domain))
            ->assertSessionHasErrors(['domain' => 'Wait until the SSL certificate has been installed or removed.']);

        $this->assertSame(DomainStatus::Active, $domain->fresh()->status);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_accounts_cannot_manage_the_certificate_of_another_accounts_domain()
    {
        $domain = Domain::factory()->sslActive()->create();
        $other = User::factory()->create();

        $this->actingAs($other)->post(route('domains.ssl.store', $domain))->assertNotFound();
        $this->actingAs($other)->delete(route('domains.ssl.destroy', $domain))->assertNotFound();
        $this->actingAs($other)->put(route('domains.force-https.update', $domain), ['force_https' => true])->assertNotFound();

        $domain->refresh();

        $this->assertSame(SslStatus::Active, $domain->ssl_status);
        $this->assertFalse($domain->force_https);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_admins_and_resellers_cannot_manage_certificates()
    {
        $domain = Domain::factory()->create();

        foreach ([User::factory()->admin()->create(), User::factory()->reseller()->create()] as $actor) {
            $this->actingAs($actor)->post(route('domains.ssl.store', $domain))->assertForbidden();
            $this->actingAs($actor)->delete(route('domains.ssl.destroy', $domain))->assertForbidden();
            $this->actingAs($actor)->put(route('domains.force-https.update', $domain), ['force_https' => true])->assertForbidden();
        }

        $this->assertNull($domain->fresh()->ssl_status);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_guests_are_redirected_to_login()
    {
        $domain = Domain::factory()->create();

        $this->post(route('domains.ssl.store', $domain))->assertRedirect(route('login'));

        $this->assertDatabaseCount('tasks', 0);
    }
}
