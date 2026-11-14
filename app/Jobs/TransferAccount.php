<?php

namespace App\Jobs;

use App\Models\AccountTransfer;
use App\Services\AccountTransferService;
use App\Services\ServerBackupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * The move of one hosting account here from another RAYXO server, on the
 * backups queue: the other server's backup can take hours, and so can its
 * download.
 */
class TransferAccount implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 2 * 6 * 3600 + 300;

    public bool $failOnTimeout = true;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public AccountTransfer $transfer)
    {
        $this->onQueue(ServerBackupService::QUEUE);
    }

    public function handle(AccountTransferService $transfers): void
    {
        $transfers->run($this->transfer);
    }

    public function failed(?Throwable $exception): void
    {
        app(AccountTransferService::class)->fail($this->transfer, __('The move did not finish.'));
    }
}
