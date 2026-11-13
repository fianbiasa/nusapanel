<?php

namespace Tests\Feature\Announcements;

use App\Enums\AccountStatus;
use App\Jobs\EmailAnnouncement;
use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AnnouncementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $reseller;

    private User $customer;

    private User $direct;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->admin = User::factory()->admin()->create();
        $this->reseller = User::factory()->reseller()->create();
        $this->customer = User::factory()->create(['owner_id' => $this->reseller->id]);
        $this->direct = User::factory()->create(['owner_id' => $this->admin->id]);
        User::factory()->create(['status' => AccountStatus::Suspended]);
    }

    public function test_an_administrator_writes_to_every_reseller_and_account()
    {
        $this->actingAs($this->admin)
            ->post(route('announcements.store'), ['subject' => 'Maintenance', 'body' => 'Saturday 22:00.', 'audience' => ['reseller', 'user'], 'email' => true])
            ->assertSessionHasNoErrors();

        $announcement = Announcement::query()->sole();
        $this->assertSame(['reseller', 'user'], $announcement->audience);
        // Active accounts only: the reseller and two hosting accounts.
        $this->assertSame(3, $announcement->recipient_count);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'announcement.sent']);

        Queue::assertPushed(EmailAnnouncement::class, fn (EmailAnnouncement $job) => count($job->userIds) === 3);

        foreach ([$this->reseller, $this->customer, $this->direct] as $user) {
            $this->actingAs($user)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('announcements.0.subject', 'Maintenance'));
        }
    }

    public function test_a_message_for_accounts_does_not_reach_resellers()
    {
        app(AnnouncementService::class)->send($this->admin, ['user'], 'For accounts', 'x', false);

        $this->actingAs($this->reseller)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('announcements', []));
        $this->actingAs($this->customer)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('announcements.0.subject', 'For accounts'));
        Queue::assertNothingPushed();
    }

    public function test_a_reseller_writes_only_to_its_own_accounts()
    {
        $otherCustomer = User::factory()->create(['owner_id' => User::factory()->reseller()->create()->id]);

        $this->actingAs($this->reseller)
            ->post(route('announcements.store'), ['subject' => 'New prices', 'body' => 'From May.', 'audience' => ['reseller'], 'email' => false])
            ->assertSessionHasNoErrors();

        $announcement = Announcement::query()->sole();
        $this->assertSame(['user'], $announcement->audience);
        $this->assertSame(1, $announcement->recipient_count);

        $this->actingAs($this->customer)->get(route('announcements.index'))->assertInertia(fn (Assert $page) => $page->where('received.0.subject', 'New prices'));
        $this->actingAs($otherCustomer)->get(route('announcements.index'))->assertInertia(fn (Assert $page) => $page->where('received', []));
        $this->actingAs($this->direct)->get(route('announcements.index'))->assertInertia(fn (Assert $page) => $page->where('received', []));
    }

    public function test_hosting_accounts_only_read()
    {
        $this->actingAs($this->customer)
            ->post(route('announcements.store'), ['subject' => 'x', 'body' => 'y', 'email' => false])
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->post(route('announcements.store'), ['subject' => 'x', 'body' => 'y', 'audience' => ['admin'], 'email' => false])
            ->assertSessionHasErrors('audience.0');

        $this->assertDatabaseCount('announcements', 0);
    }

    public function test_each_account_gets_its_own_email()
    {
        config(['mail.default' => 'array']);
        $announcement = app(AnnouncementService::class)->send($this->reseller, [], 'Notice', 'Body text.', false);
        $second = User::factory()->create(['owner_id' => $this->reseller->id]);

        (new EmailAnnouncement($announcement->id, [$this->customer->id, $second->id]))->handle();

        $sent = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(2, $sent);
        $this->assertSame([$this->customer->email], array_map(fn ($address) => $address->getAddress(), $sent[0]->getOriginalMessage()->getTo()));
        $this->assertSame($this->reseller->name, $sent[0]->getOriginalMessage()->getFrom()[0]->getName());
        $this->assertSame('Notice', $sent[1]->getOriginalMessage()->getSubject());
    }

    public function test_the_dashboard_shows_the_last_month_only()
    {
        $old = Announcement::factory()->create(['sender_id' => $this->admin->id, 'subject' => 'Old']);
        $old->forceFill(['created_at' => now()->subDays(40)])->save();

        $this->actingAs($this->customer)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('announcements', []));
        $this->actingAs($this->customer)->get(route('announcements.index'))->assertInertia(fn (Assert $page) => $page->where('received.0.subject', 'Old'));
    }
}
