<?php

namespace App\Services;

use App\Models\User;

/**
 * Writes the server's configuration again from the panel's database, like
 * DirectAdmin's rewrite_confs: every website (Nginx, Apache and its PHP
 * pool), every account's disk quota and crontab, and every FTP login's
 * folder. Each task renders the full state, so a rebuild changes nothing
 * on a server that matches the database, however often it runs.
 */
class RebuildService
{
    public function __construct(
        private DomainService $domains,
        private QuotaService $quotas,
        private CronJobService $cronJobs,
        private FtpService $ftp,
        private PhpVersionService $phpVersions,
    ) {}

    /**
     * Queue the whole rebuild; the queue worker and the agent do the rest.
     *
     * @return array{websites: int, quotas: int, crontabs: int, ftp_folders: int}
     */
    public function queueAll(?User $actor = null): array
    {
        $this->phpVersions->forget();

        return [
            'websites' => $this->domains->reapplyAll($actor),
            'quotas' => $this->quotas->applyAll($actor),
            'crontabs' => $this->cronJobs->reapplyAll($actor),
            'ftp_folders' => $this->ftp->reapplyAll($actor),
        ];
    }
}
