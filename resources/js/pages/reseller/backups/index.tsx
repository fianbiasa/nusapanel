import { Head, usePoll } from '@inertiajs/react';
import { PageHeader } from '@/components/page-header';
import {
    AccountRestoresPanel,
    BackupNowPanel,
    ServerBackupsPanel,
} from '@/components/server-backups';
import type { ServerBackupsProps } from '@/components/server-backups';

/**
 * A reseller's backups of its hosting accounts: those the administrator's
 * automatic backups made and those the reseller makes by hand, kept on the
 * server. Backups of deleted accounts make the account again.
 */
export default function ResellerBackups({
    scope,
    backups,
    filters,
    accounts,
    manual_keep,
    restores,
    inspected,
}: ServerBackupsProps) {
    const busy = backups.data.some(
        (backup) => backup.status === 'queued' || backup.status === 'running',
    );

    usePoll(5000, { only: ['backups', 'restores'] }, { autoStart: busy });

    return (
        <>
            <Head title="Backup" />

            <PageHeader
                title="Backup"
                description="Backup akun hosting kamu yang disimpan di server: dari backup otomatis yang diatur admin, dan yang kamu buat sendiri."
            />

            <BackupNowPanel
                scope={scope}
                accounts={accounts}
                manualKeep={manual_keep}
            />

            <AccountRestoresPanel restores={restores} />

            <ServerBackupsPanel
                scope={scope}
                backups={backups}
                filters={filters}
                inspected={inspected}
            />
        </>
    );
}
