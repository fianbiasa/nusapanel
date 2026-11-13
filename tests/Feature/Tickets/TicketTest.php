<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketStatus;
use App\Jobs\DeliverAlert;
use App\Models\AuditLog;
use App\Models\NotificationChannel;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TicketTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $reseller;

    private User $account;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->admin = User::factory()->admin()->create();
        $this->reseller = User::factory()->reseller()->create(['username' => 'jual']);
        $this->account = User::factory()->create(['username' => 'budi', 'owner_id' => $this->reseller->id]);

        foreach ([$this->admin, $this->reseller, $this->account] as $user) {
            NotificationChannel::factory()->for($user)->create(['events' => ['ticket']]);
        }
    }

    public function test_an_account_writes_to_its_reseller()
    {
        $this->actingAs($this->account)
            ->post(route('tickets.store'), ['subject' => 'Website down', 'priority' => 'high', 'body' => 'It shows 500.'])
            ->assertRedirect(route('tickets.show', Ticket::query()->sole()));

        $ticket = Ticket::query()->sole();
        $this->assertSame($this->reseller->id, $ticket->reseller_id);
        $this->assertSame(TicketStatus::Open, $ticket->status);
        $this->assertSame('It shows 500.', $ticket->messages()->sole()->body);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'ticket.opened']);

        // Only the reseller hears about it.
        Queue::assertPushed(DeliverAlert::class, 1);
        Queue::assertPushed(DeliverAlert::class, fn (DeliverAlert $job) => $job->channelId === $this->reseller->notificationChannels()->value('id')
            && $job->subject === 'New ticket from budi: Website down');
    }

    public function test_accounts_of_administrators_and_resellers_write_to_the_administrators()
    {
        $direct = User::factory()->create(['owner_id' => $this->admin->id]);

        $this->actingAs($direct)->post(route('tickets.store'), ['subject' => 'A', 'priority' => 'normal', 'body' => 'x'])->assertSessionHasNoErrors();
        $this->actingAs($this->reseller)->post(route('tickets.store'), ['subject' => 'B', 'priority' => 'low', 'body' => 'y'])->assertSessionHasNoErrors();

        $this->assertSame([null, null], Ticket::query()->orderBy('id')->pluck('reseller_id')->all());

        $this->actingAs($this->admin)->post(route('tickets.store'), ['subject' => 'C', 'priority' => 'low', 'body' => 'z'])->assertSessionHasErrors('subject');
    }

    public function test_answers_and_replies_move_the_status()
    {
        $ticket = $this->openTicket();

        $this->actingAs($this->reseller)->post(route('tickets.reply', $ticket), ['body' => 'Fixed.'])->assertSessionHasNoErrors();
        $this->assertSame(TicketStatus::Answered, $ticket->fresh()->status);
        Queue::assertPushed(DeliverAlert::class, fn (DeliverAlert $job) => $job->channelId === $this->account->notificationChannels()->value('id')
            && str_contains($job->body, 'Fixed.') && str_contains($job->body, route('tickets.show', $ticket)));

        $this->actingAs($this->account)->post(route('tickets.reply', $ticket), ['body' => 'Still broken.'])->assertSessionHasNoErrors();
        $this->assertSame(TicketStatus::Open, $ticket->fresh()->status);

        $this->actingAs($this->account)->post(route('tickets.close', $ticket))->assertSessionHasNoErrors();
        $this->assertSame(TicketStatus::Closed, $ticket->fresh()->status);

        $this->actingAs($this->reseller)->post(route('tickets.reopen', $ticket))->assertSessionHasNoErrors();
        $this->assertSame(TicketStatus::Open, $ticket->fresh()->status);

        $this->actingAs($this->account)
            ->get(route('tickets.show', $ticket))
            ->assertInertia(fn (Assert $page) => $page
                ->component('tickets/show')
                ->has('messages', 3)
                ->where('messages.1.author.role', 'reseller')
                ->where('messages.2.mine', true)
                ->where('ticket.answering', false));
    }

    public function test_only_its_two_sides_see_a_ticket()
    {
        $ticket = $this->openTicket();
        $otherReseller = User::factory()->reseller()->create();

        foreach ([$this->admin, $otherReseller, User::factory()->create()] as $outsider) {
            $this->actingAs($outsider)->get(route('tickets.show', $ticket))->assertNotFound();
            $this->actingAs($outsider)->post(route('tickets.reply', $ticket), ['body' => 'x'])->assertNotFound();
            $this->actingAs($outsider)->post(route('tickets.close', $ticket))->assertNotFound();
        }

        $this->assertSame(1, $ticket->messages()->count());
    }

    public function test_the_list_shows_what_waits_for_whom()
    {
        $ticket = $this->openTicket();

        $this->actingAs($this->reseller)
            ->get(route('tickets.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('tickets/index')
                ->where('box', 'mine')
                ->where('waiting.inbox', 1)
                ->has('tickets.data', 0));

        $this->actingAs($this->reseller)
            ->get(route('tickets.index', ['box' => 'inbox']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('tickets.data.0.subject', $ticket->subject)
                ->where('tickets.data.0.from.username', 'budi'));

        // A hosting account has no inbox.
        $this->actingAs($this->account)
            ->get(route('tickets.index', ['box' => 'inbox']))
            ->assertInertia(fn (Assert $page) => $page->where('box', 'mine')->where('recipient', 'reseller')->has('tickets.data', 1));
    }

    private function openTicket(): Ticket
    {
        $this->actingAs($this->account)->post(route('tickets.store'), ['subject' => 'Website down', 'priority' => 'normal', 'body' => 'It shows 500.']);

        return Ticket::query()->sole();
    }
}
