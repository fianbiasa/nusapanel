<?php

namespace App\Console\Commands;

use App\Services\FileManagerService;
use Illuminate\Console\Command;

/**
 * Removes what the file manager left in the transfer folder: uploads that
 * were never finished and downloads that were never sent. The scheduler
 * runs it every hour.
 */
class CleanTransfersCommand extends Command
{
    /**
     * Files this old (in hours) are no longer part of a transfer.
     */
    private const MAX_AGE_HOURS = 6;

    /**
     * @var string
     */
    protected $signature = 'rayxo:clean-transfers';

    /**
     * @var string
     */
    protected $description = 'Remove abandoned file manager uploads and downloads';

    public function handle(): int
    {
        $cutoff = now()->subHours(self::MAX_AGE_HOURS)->getTimestamp();
        $removed = 0;

        foreach (glob(FileManagerService::transferDir().'/*.{upload,download}', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file) && ! is_link($file) && filemtime($file) < $cutoff && unlink($file)) {
                $removed++;
            }
        }

        $this->components->info($removed === 1 ? '1 abandoned transfer removed.' : "{$removed} abandoned transfers removed.");

        return self::SUCCESS;
    }
}
