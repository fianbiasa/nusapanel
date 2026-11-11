<?php

namespace App\Console\Commands;

use App\Services\FtpService;
use Illuminate\Console\Command;

/**
 * Counts the folder of every FTP login with a quota again. Pure-FTPd only
 * counts what passes through FTP, so the scheduler runs this every night to
 * take in files added or deleted through the file manager, SFTP or the
 * website itself.
 */
class RecountFtpQuotasCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'rayxo:recount-ftp-quotas';

    /**
     * @var string
     */
    protected $description = 'Count the folders of FTP logins with a quota again, through the server agent';

    public function handle(FtpService $ftp): int
    {
        ['counted' => $counted, 'failed' => $failed] = $ftp->recountQuotas();

        $this->components->info($counted === 1 ? '1 folder counted.' : "{$counted} folders counted.");

        if ($failed > 0) {
            $this->components->error($failed === 1 ? '1 folder could not be counted.' : "{$failed} folders could not be counted.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
