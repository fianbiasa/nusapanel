<?php

namespace App\Console\Commands;

use App\Services\PhpMyAdminService;
use Illuminate\Console\Command;

/**
 * Ends the phpMyAdmin sessions whose time is up, so the MariaDB password
 * handed to phpMyAdmin stops working. The scheduler runs it every five
 * minutes.
 */
class ClosePhpMyAdminSessionsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'nusapanel:close-phpmyadmin-sessions';

    /**
     * @var string
     */
    protected $description = 'End the phpMyAdmin sessions whose time is up';

    public function handle(PhpMyAdminService $phpMyAdmin): int
    {
        $closed = $phpMyAdmin->closeExpiredSessions();

        $this->components->info($closed === 1 ? '1 phpMyAdmin session ended.' : "{$closed} phpMyAdmin sessions ended.");

        return self::SUCCESS;
    }
}
