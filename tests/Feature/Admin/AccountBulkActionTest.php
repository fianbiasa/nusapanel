<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountStatus;
use App\Models\Package;
use App\Models\Task;
use App\Models\User;
use App\Services\AccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountBulkActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_many_accounts_are_suspended_and_the_unfit_ones_are_skipped()
    {
        $admin = User::factory()->admin()->create(['username' => 'boss']);
        $budi = User::factory()->create(['username' => 'budi']);
        $sari = User::factory()->create(['username' => 'sari']);
        $dodi = User::factory()->suspended('Old reason')->create(['username' => 'dodi']);
        $otherAdmin = User::factory()->admin()->create(['username' => 'root2']);

        $this->actingAs($admin)
            ->from(route('admin.accounts.index'))
            ->post(route('admin.accounts.bulk'), [
                'action' => 'suspend',
                'ids' => [$budi->id, $sari->id, $dodi->id, $otherAdmin->id, $admin->id],
                'reason' => 'Unpaid invoice',
            ])
            ->assertRedirect(route('admin.accounts.index'))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'warning')
            ->assertInertiaFlash('toast.message', 'Accounts suspended: 2. Skipped: boss (You cannot suspend your own account.); dodi (Only active accounts can be suspended.); root2 (You cannot suspend an administrator account.)');

        foreach ([$budi, $sari] as $account) {
            $account->refresh();
            $this->assertSame(AccountStatus::Suspended, $account->status);
            $this->assertSame('Unpaid invoice', $account->suspend_reason);
        }

        $this->assertSame('Old reason', $dodi->fresh()->suspend_reason);
        $this->assertSame(AccountStatus::Active, $otherAdmin->fresh()->status);
        $this->assertSame(['budi', 'sari'], Task::query()->where('action', 'account.suspend')->orderBy('id')->get()->pluck('payload.username')->all());
    }

    public function test_many_accounts_are_unsuspended_or_deleted()
    {
        $admin = User::factory()->admin()->create();
        $budi = User::factory()->suspended()->create();
        $sari = User::factory()->suspended()->create();

        $this->actingAs($admin)
            ->post(route('admin.accounts.bulk'), ['action' => 'unsuspend', 'ids' => [$budi->id, $sari->id]])
            ->assertInertiaFlash('toast.type', 'success')
            ->assertInertiaFlash('toast.message', 'Accounts unsuspended: 2.');

        $this->assertSame(AccountStatus::Active, $budi->fresh()->status);
        $this->assertSame(AccountStatus::Active, $sari->fresh()->status);

        $this->actingAs($admin)
            ->post(route('admin.accounts.bulk'), ['action' => 'delete', 'ids' => [$budi->id, $sari->id]])
            ->assertInertiaFlash('toast.message', 'Accounts being deleted: 2.');

        $this->assertSame(AccountStatus::Deleting, $budi->fresh()->status);
        $this->assertSame(AccountStatus::Deleting, $sari->fresh()->status);
        $this->assertSame(2, Task::query()->where('action', 'account.delete')->count());
    }

    public function test_many_accounts_change_package_but_only_hosting_accounts()
    {
        $admin = User::factory()->admin()->create();
        $gold = Package::factory()->create(['name' => 'Gold']);
        $budi = User::factory()->create(['username' => 'budi']);
        $sari = User::factory()->create(['username' => 'sari', 'package_id' => $gold->id]);
        $reseller = User::factory()->reseller()->create(['username' => 'agen']);

        $this->actingAs($admin)
            ->post(route('admin.accounts.bulk'), ['action' => 'package', 'ids' => [$budi->id, $sari->id, $reseller->id], 'package_id' => $gold->id])
            ->assertInertiaFlash('toast.message', 'Accounts moved to the Gold package: 2. Skipped: agen (Only hosting accounts can be put on a package.)');

        $this->assertSame($gold->id, $budi->fresh()->package_id);
        $this->assertNull($reseller->fresh()->package_id);

        $this->actingAs($admin)
            ->post(route('admin.accounts.bulk'), ['action' => 'package', 'ids' => [$budi->id, $sari->id], 'package_id' => ''])
            ->assertInertiaFlash('toast.message', 'Accounts taken off packages: 2.');

        $this->assertNull($budi->fresh()->package_id);
        $this->assertNull($sari->fresh()->package_id);
    }

    public function test_nothing_done_is_reported_as_an_error()
    {
        $admin = User::factory()->admin()->create();
        $budi = User::factory()->create(['username' => 'budi']);

        $this->actingAs($admin)
            ->post(route('admin.accounts.bulk'), ['action' => 'unsuspend', 'ids' => [$budi->id]])
            ->assertInertiaFlash('toast.type', 'error')
            ->assertInertiaFlash('toast.message', 'Skipped: budi (Only suspended accounts can be unsuspended.)');

        $this->assertDatabaseCount('tasks', 0);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidRequests(): array
    {
        return [
            'unknown action' => [['action' => 'rename', 'ids' => [1]], 'action'],
            'no accounts' => [['action' => 'suspend', 'ids' => []], 'ids'],
            'too many accounts' => [['action' => 'suspend', 'ids' => range(1, AccountService::MAX_BULK + 1)], 'ids'],
            'repeated account' => [['action' => 'suspend', 'ids' => [1, 1]], 'ids.0'],
            'no package given' => [['action' => 'package', 'ids' => [1]], 'package_id'],
            'unknown package' => [['action' => 'package', 'ids' => [1], 'package_id' => 999], 'package_id'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('invalidRequests')]
    public function test_invalid_requests_change_nothing(array $data, string $error)
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.accounts.bulk'), $data)
            ->assertSessionHasErrors($error);

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_only_admins_may_use_bulk_actions()
    {
        $account = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($account)
            ->post(route('admin.accounts.bulk'), ['action' => 'suspend', 'ids' => [$other->id]])
            ->assertForbidden();

        $this->actingAs(User::factory()->reseller()->create())
            ->post(route('admin.accounts.bulk'), ['action' => 'suspend', 'ids' => [$other->id]])
            ->assertForbidden();

        $this->assertSame(AccountStatus::Active, $other->fresh()->status);
    }
}
