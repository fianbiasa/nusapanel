<?php

namespace Tests\Feature\Email;

use App\Enums\MailDomainMode;
use App\Enums\MailDomainStatus;
use App\Enums\SslStatus;
use App\Enums\TaskStatus;
use App\Events\AgentTaskFinished;
use App\Models\Domain;
use App\Models\MailDomain;
use App\Models\Task;
use App\Models\User;
use App\Services\DomainService;
use App\Services\MailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MailSslTest extends TestCase
{
    use RefreshDatabase;

    private User $account;

    private Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['hosting.public_ip' => '203.0.113.10']);

        $this->account = User::factory()->create(['username' => 'budi', 'uid' => 1001, 'gid' => 1001]);
        $this->domain = $this->app->make(DomainService::class)->create($this->account, 'example.com', $this->account);
        Task::query()->delete();
    }

    /**
     * @param  array<string, mixed>  $output
     */
    private function finish(string $action, TaskStatus $status, array $output = [], ?string $error = null): void
    {
        $task = Task::query()->where('action', $action)->latest('id')->firstOrFail();
        $task->forceFill(['status' => $status, 'output' => $output, 'error' => $error])->save();

        event(new AgentTaskFinished($task));
    }

    public function test_a_domain_that_takes_mail_gets_a_certificate_for_mail_dot_domain()
    {
        $this->actingAs($this->account)
            ->post(route('email.store'), ['local_part' => 'info', 'domain_id' => $this->domain->id, 'password' => 'a-long-password-1', 'quota_mb' => 100])
            ->assertSessionHasNoErrors();

        $this->finish('mail.domain_apply', TaskStatus::Success, ['dkim_public_key' => 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAtest']);

        $mailDomain = MailDomain::query()->sole();
        $this->assertSame(SslStatus::Pending, $mailDomain->ssl_status);
        $this->assertSame(['username' => 'budi', 'domain' => 'example.com'], Task::query()->where('action', 'mail.ssl_issue')->sole()->payload);

        $this->finish('mail.ssl_issue', TaskStatus::Success, ['name' => 'mail.example.com', 'source' => 'mail']);

        $this->assertSame(SslStatus::Active, $mailDomain->refresh()->ssl_status);
        $this->assertSame(['domains' => ['example.com']], Task::query()->where('action', 'mail.sni_apply')->sole()->payload);

        $this->actingAs($this->account)
            ->get(route('email.index'))
            ->assertInertia(fn (Assert $page) => $page->where('domains.0.mail_ssl', 'active'));
    }

    public function test_a_failed_certificate_can_be_asked_for_again_by_its_account_only()
    {
        $mailDomain = MailDomain::factory()->for($this->domain)->create();

        $this->actingAs($this->account)->post(route('email.ssl.store', $mailDomain))->assertSessionHasNoErrors();
        $this->finish('mail.ssl_issue', TaskStatus::Failed, error: 'mail.example.com does not reach this server over HTTP (no DNS record)');

        $this->assertSame(SslStatus::Failed, $mailDomain->refresh()->ssl_status);
        $this->actingAs($this->account)
            ->get(route('email.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('domains.0.mail_ssl', 'failed')
                ->where('domains.0.mail_ssl_error', 'mail.example.com does not reach this server over HTTP (no DNS record)'));

        $this->actingAs($this->account)->post(route('email.ssl.store', $mailDomain))->assertSessionHasNoErrors();
        $this->assertSame(SslStatus::Pending, $mailDomain->refresh()->ssl_status);
        $this->assertNull($mailDomain->ssl_error);

        // Asked again while pending, nothing more is queued.
        $this->actingAs($this->account)->post(route('email.ssl.store', $mailDomain))->assertSessionHasNoErrors();
        $this->assertSame(2, Task::query()->where('action', 'mail.ssl_issue')->count());

        $this->actingAs(User::factory()->create(['username' => 'sari']))
            ->post(route('email.ssl.store', $mailDomain))
            ->assertNotFound();
    }

    public function test_a_domain_whose_email_is_not_ready_or_goes_elsewhere_gets_none()
    {
        $mailDomain = MailDomain::factory()->for($this->domain)->create(['status' => MailDomainStatus::Pending]);

        $this->actingAs($this->account)
            ->post(route('email.ssl.store', $mailDomain))
            ->assertSessionHasErrors(['ssl' => 'The email of example.com is still being set up.']);

        $mailDomain->forceFill(['mode' => MailDomainMode::External])->save();
        $this->app->make(MailService::class)->completeDomainApply($mailDomain, [], null);

        $this->assertNull($mailDomain->refresh()->ssl_status);
        $this->assertSame(0, Task::query()->where('action', 'mail.ssl_issue')->count());
    }

    public function test_the_daily_sync_asks_for_domains_never_asked_and_serves_them_all()
    {
        $other = User::factory()->create(['username' => 'sari']);
        $never = MailDomain::factory()->for($this->domain)->create();
        MailDomain::factory()->for(Domain::factory()->for($other, 'account')->create(['name' => 'sari.example']))->create(['ssl_status' => SslStatus::Active]);
        MailDomain::factory()->for(Domain::factory()->for($other, 'account')->create(['name' => 'google.example']))->create(['mode' => MailDomainMode::External]);
        MailDomain::factory()->for(Domain::factory()->for($other, 'account')->create(['name' => 'later.example']))->create(['status' => MailDomainStatus::Pending]);

        $this->artisan('rayxo:sync-mail-ssl')->assertSuccessful();

        $this->assertSame(SslStatus::Pending, $never->refresh()->ssl_status);
        $this->assertSame(['example.com'], Task::query()->where('action', 'mail.ssl_issue')->get()->pluck('payload.domain')->all());
        $this->assertSame(
            ['domains' => ['example.com', 'google.example', 'sari.example']],
            Task::query()->where('action', 'mail.sni_apply')->sole()->payload,
        );
    }
}
