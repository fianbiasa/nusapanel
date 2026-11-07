<?php

namespace Tests\Feature\Domains;

use App\Enums\AccountStatus;
use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\User;
use App\Services\DomainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Cases the domains page cannot reach: accounts that cannot sign in to it,
 * and two requests racing for the same name.
 */
class DomainServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_domains_added_to_a_pending_account_wait_for_the_account_to_be_created()
    {
        $account = User::factory()->create(['status' => AccountStatus::Pending]);

        $domain = $this->app->make(DomainService::class)
            ->create($account, 'example.com', User::factory()->admin()->create());

        $this->assertSame(DomainStatus::Pending, $domain->fresh()->status);
        $this->assertDatabaseCount('tasks', 0);
        Queue::assertNothingPushed();
    }

    /**
     * @return array<string, array{AccountStatus}>
     */
    public static function unavailableAccountStatuses(): array
    {
        return [
            'suspended' => [AccountStatus::Suspended],
            'failed' => [AccountStatus::Failed],
            'deleting' => [AccountStatus::Deleting],
        ];
    }

    #[DataProvider('unavailableAccountStatuses')]
    public function test_domains_cannot_be_added_to_accounts_that_are_not_active_or_pending(AccountStatus $status)
    {
        $account = User::factory()->create(['status' => $status]);

        try {
            $this->app->make(DomainService::class)->create($account, 'example.com', User::factory()->admin()->create());
            $this->fail('The domain was added.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['domain' => ['Domains cannot be added while the account is suspended, failed or being deleted.']],
                $exception->errors(),
            );
        }

        $this->assertDatabaseCount('domains', 0);
        Queue::assertNothingPushed();
    }

    public function test_domains_cannot_be_added_to_admins_or_resellers()
    {
        foreach ([User::factory()->admin()->create(), User::factory()->reseller()->create()] as $account) {
            try {
                $this->app->make(DomainService::class)->create($account, 'example.com', $account);
                $this->fail('The domain was added.');
            } catch (ValidationException $exception) {
                $this->assertSame(['domain' => ['Domains can only be added to hosting accounts.']], $exception->errors());
            }
        }

        $this->assertDatabaseCount('domains', 0);
        Queue::assertNothingPushed();
    }

    public function test_a_name_taken_by_another_account_while_saving_is_reported_as_in_use()
    {
        $account = User::factory()->create();
        $other = User::factory()->create();

        // Another request saves the same name between the conflict check and the insert.
        Domain::creating(fn (Domain $domain) => DB::table('domains')->insert([
            'user_id' => $other->id,
            'name' => $domain->name,
            'php_version' => '8.4',
            'status' => DomainStatus::Active->value,
        ]));

        try {
            $this->app->make(DomainService::class)->create($account, 'example.com', $account);
            $this->fail('The domain was added.');
        } catch (ValidationException $exception) {
            $this->assertSame(['domain' => ['This domain is already in use by another account.']], $exception->errors());
        }

        $this->assertFalse($account->domains()->exists());
        Queue::assertNothingPushed();
    }
}
