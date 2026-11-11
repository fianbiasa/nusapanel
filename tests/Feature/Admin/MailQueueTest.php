<?php

namespace Tests\Feature\Admin;

use App\Agent\AgentClient;
use App\Agent\AgentException;
use App\Agent\FakeAgentClient;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MailQueueTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private FakeAgentClient $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->agent = $this->app->instance(AgentClient::class, new FakeAgentClient);
    }

    public function test_hosting_accounts_cannot_see_or_touch_the_queue()
    {
        $account = User::factory()->create();

        $this->actingAs($account)->get(route('admin.mail-queue.index'))->assertForbidden();
        $this->actingAs($account)->post(route('admin.mail-queue.retry'))->assertForbidden();
        $this->actingAs($account)->delete(route('admin.mail-queue.destroy'), ['ids' => ['4C2E520ABA']])->assertForbidden();

        $this->assertSame([], $this->agent->calls());
    }

    public function test_the_admin_sees_the_queue_with_why_mail_waits()
    {
        $this->actingAs($this->admin)
            ->get(route('admin.mail-queue.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/mail-queue/index')
                ->where('error', null)
                ->where('queue.total', 2)
                ->where('queue.messages.0.queue', 'deferred')
                ->where('queue.messages.0.recipients.0.reason', 'connect to mx.example.org[192.0.2.1]:25: Connection timed out')
                ->where('queue.messages.1.queue', 'maildrop'));
    }

    public function test_an_agent_that_does_not_answer_shows_an_error_instead_of_the_queue()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('call')->once()->andThrow(new AgentException('agent is not running')));

        $this->actingAs($this->admin)
            ->get(route('admin.mail-queue.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('queue', null)
                ->where('error', 'The agent did not answer. Check that rayxod is running: systemctl status rayxod'));
    }

    public function test_the_admin_tries_one_message_or_all_of_them_again()
    {
        $this->actingAs($this->admin)
            ->post(route('admin.mail-queue.retry'), ['id' => '4C2E520ABA'])
            ->assertInertiaFlash('toast.message', 'Message 4C2E520ABA is being tried again.');

        $this->actingAs($this->admin)
            ->post(route('admin.mail-queue.retry'))
            ->assertInertiaFlash('toast.message', 'Every waiting message is being tried again.');

        $this->assertSame([
            ['method' => 'mail.queue_retry', 'params' => ['id' => '4C2E520ABA']],
            ['method' => 'mail.queue_retry', 'params' => ['id' => '']],
        ], $this->agent->calls());
        $this->assertSame(2, AuditLog::query()->where('action', 'mail.queue_retried')->count());
    }

    public function test_the_admin_deletes_messages()
    {
        $this->actingAs($this->admin)
            ->delete(route('admin.mail-queue.destroy'), ['ids' => ['4C2E520ABA', 'D659820AD2']])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', '2 messages deleted from the queue.');

        $this->assertSame([['method' => 'mail.queue_delete', 'params' => ['ids' => ['4C2E520ABA', 'D659820AD2']]]], $this->agent->calls());
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'mail.queue_deleted', 'actor_id' => $this->admin->id]);
    }

    public function test_messages_already_gone_are_reported()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('call')->once()->with('mail.queue_delete', ['ids' => ['4C2E520ABA', 'D659820AD2']], 60)
            ->andReturn(['done' => ['4C2E520ABA'], 'failed' => [['id' => 'D659820AD2', 'message' => 'not in the queue (sent or removed already)']]]));

        $this->actingAs($this->admin)
            ->delete(route('admin.mail-queue.destroy'), ['ids' => ['4C2E520ABA', 'D659820AD2']])
            ->assertInertiaFlash('toast.message', '1 of 2 messages deleted; the others were no longer in the queue.');
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidRequests(): array
    {
        return [
            'no ids' => [['ids' => []], 'ids'],
            'a path' => [['ids' => ['../../etc/passwd']], 'ids.0'],
            'an option' => [['ids' => ['-d']], 'ids.0'],
            'postsuper ALL' => [['ids' => ['ALL deferred']], 'ids.0'],
            'twice' => [['ids' => ['4C2E520ABA', '4C2E520ABA']], 'ids.0'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('invalidRequests')]
    public function test_invalid_queue_ids_never_reach_the_agent(array $data, string $error)
    {
        $this->actingAs($this->admin)
            ->delete(route('admin.mail-queue.destroy'), $data)
            ->assertSessionHasErrors($error);

        $this->actingAs($this->admin)
            ->post(route('admin.mail-queue.retry'), ['id' => '-f'])
            ->assertSessionHasErrors('id');

        $this->assertSame([], $this->agent->calls());
    }

    public function test_an_agent_error_on_delete_is_shown()
    {
        $this->mock(AgentClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('call')->once()->andThrow(new AgentException('postsuper failed', -32000)));

        $this->actingAs($this->admin)
            ->delete(route('admin.mail-queue.destroy'), ['ids' => ['4C2E520ABA']])
            ->assertSessionHasErrors(['queue' => 'The mail could not be deleted: postsuper failed']);

        $this->assertDatabaseMissing(AuditLog::class, ['action' => 'mail.queue_deleted']);
    }
}
