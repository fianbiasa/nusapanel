<?php

namespace Tests\Feature\Tickets;

use App\Jobs\DeliverAlert;
use App\Models\NotificationChannel;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TicketAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private User $reseller;

    private User $account;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('local');

        $this->reseller = User::factory()->reseller()->create(['username' => 'jual']);
        $this->account = User::factory()->create(['username' => 'budi', 'owner_id' => $this->reseller->id]);
        NotificationChannel::factory()->for($this->reseller)->create(['events' => ['ticket']]);
    }

    public function test_messages_carry_files_that_only_the_two_sides_of_the_ticket_open()
    {
        $this->actingAs($this->account)
            ->post(route('tickets.store'), [
                'subject' => 'Website down',
                'priority' => 'high',
                'body' => 'See the screenshot.',
                'attachments' => [UploadedFile::fake()->image('../error 500.png', 40, 30), UploadedFile::fake()->create('invoice.pdf', 120, 'application/pdf')],
            ])
            ->assertSessionHasNoErrors();

        $ticket = Ticket::query()->sole();
        [$image, $pdf] = TicketAttachment::query()->orderBy('id')->get()->all();
        $this->assertSame(['error 500.png', 'image/png', true], [$image->name, $image->mime, $image->isImage()]);
        $this->assertSame(['invoice.pdf', false], [$pdf->name, $pdf->isImage()]);
        Storage::disk('local')->assertExists([$image->path, $pdf->path]);
        $this->assertStringStartsWith("tickets/{$ticket->id}/", $image->path);
        Queue::assertPushed(DeliverAlert::class, fn (DeliverAlert $job) => str_contains($job->body, 'Attachments: error 500.png, invoice.pdf'));

        $this->actingAs($this->reseller)
            ->get(route('tickets.show', $ticket))
            ->assertInertia(fn (Assert $page) => $page
                ->where('messages.0.attachments.0.name', 'error 500.png')
                ->where('messages.0.attachments.0.image', true)
                ->where('messages.0.attachments.1.size', 120 * 1024)
                ->where('messages.0.attachments.1.url', route('tickets.attachment', [$ticket, $pdf])));

        // A picture is shown, anything else downloaded; neither may run as a page.
        $this->actingAs($this->reseller)
            ->get(route('tickets.attachment', [$ticket, $image]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
        $this->actingAs($this->account)
            ->get(route('tickets.attachment', [$ticket, $pdf]))
            ->assertOk()
            ->assertDownload('invoice.pdf')
            ->assertHeader('Content-Type', 'application/octet-stream');

        foreach ([User::factory()->admin()->create(), User::factory()->reseller()->create(), User::factory()->create()] as $outsider) {
            $this->actingAs($outsider)->get(route('tickets.attachment', [$ticket, $image]))->assertNotFound();
        }

        // Another ticket's file is not reached through this ticket.
        $other = Ticket::factory()->for($this->account)->create(['reseller_id' => $this->reseller->id]);
        $this->actingAs($this->account)->get(route('tickets.attachment', [$other, $image]))->assertNotFound();
    }

    public function test_replies_carry_files_too()
    {
        $ticket = Ticket::factory()->for($this->account)->create(['reseller_id' => $this->reseller->id]);

        $this->actingAs($this->reseller)
            ->post(route('tickets.reply', $ticket), ['body' => 'Try this.', 'attachments' => [UploadedFile::fake()->createWithContent('fix.txt', "line\n")]])
            ->assertSessionHasNoErrors();

        $this->assertSame('fix.txt', $ticket->messages()->sole()->attachments()->sole()->name);
    }

    public function test_files_are_limited_in_number_size_and_kind()
    {
        $ticket = Ticket::factory()->for($this->account)->create(['reseller_id' => $this->reseller->id]);
        $reply = fn (array $files) => $this->actingAs($this->account)->post(route('tickets.reply', $ticket), ['body' => 'x', 'attachments' => $files]);

        $reply(array_map(fn (int $i): UploadedFile => UploadedFile::fake()->create("{$i}.pdf", 1, 'application/pdf'), range(1, 6)))->assertSessionHasErrors('attachments');
        $reply([UploadedFile::fake()->create('big.pdf', 5121, 'application/pdf')])->assertSessionHasErrors('attachments.0');
        $reply([UploadedFile::fake()->createWithContent('page.html', '<script>alert(1)</script>')])->assertSessionHasErrors('attachments.0');
        $reply([UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>')])->assertSessionHasErrors('attachments.0');

        $this->assertSame(0, $ticket->messages()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_deleting_an_account_removes_the_files_of_its_tickets()
    {
        $this->actingAs($this->reseller)
            ->post(route('tickets.store'), ['subject' => 'Help', 'priority' => 'low', 'body' => 'x', 'attachments' => [UploadedFile::fake()->image('a.png')]])
            ->assertSessionHasNoErrors();
        $this->account->forceFill(['owner_id' => User::factory()->admin()->create()->id])->save();
        $kept = Ticket::factory()->for($this->account)->create(['reseller_id' => null]);
        Storage::disk('local')->put("tickets/{$kept->id}/keep.png", 'x');

        $this->actingAs(User::factory()->admin()->create())
            ->delete(route('admin.accounts.destroy', $this->reseller))
            ->assertSessionHasNoErrors();

        $this->assertModelMissing($this->reseller);
        $this->assertSame(["tickets/{$kept->id}/keep.png"], Storage::disk('local')->allFiles());
    }
}
