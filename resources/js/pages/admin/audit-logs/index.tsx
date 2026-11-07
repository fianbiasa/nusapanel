import { Head } from '@inertiajs/react';
import { ScrollText } from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { Panel, tableStyles } from '@/components/panel';
import { auditActionLabels, formatDateTime } from '@/lib/format';
import type { Paginated } from '@/types';

type AuditLogRow = {
    id: number;
    action: string;
    actor: string | null;
    subject: string | null;
    ip_address: string | null;
    changes: Record<string, unknown> | null;
    created_at: string;
};

export default function AuditLogsIndex({
    logs,
}: {
    logs: Paginated<AuditLogRow>;
}) {
    return (
        <>
            <Head title="Audit Log" />

            <PageHeader
                title="Audit Log"
                description="Siapa melakukan apa, kapan, dan dari mana. Catatan tidak bisa diubah."
            />

            <Panel flush title="Catatan" description={`${logs.total} catatan`}>
                {logs.data.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 px-6 pt-4 pb-10 text-center">
                        <span className="flex size-14 items-center justify-center rounded-full bg-accent text-accent-foreground">
                            <ScrollText className="size-6" />
                        </span>
                        <p className="text-sm text-muted-foreground">
                            Belum ada catatan.
                        </p>
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className={tableStyles.table}>
                            <thead className={tableStyles.head}>
                                <tr>
                                    <th className={tableStyles.th}>Waktu</th>
                                    <th className={tableStyles.th}>Aksi</th>
                                    <th className={tableStyles.th}>Oleh</th>
                                    <th className={tableStyles.th}>Objek</th>
                                    <th className={tableStyles.th}>
                                        Alamat IP
                                    </th>
                                    <th className={tableStyles.th}>Detail</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {logs.data.map((log) => (
                                    <tr
                                        key={log.id}
                                        className={`${tableStyles.row} align-top`}
                                    >
                                        <td
                                            className={`${tableStyles.td} whitespace-nowrap text-muted-foreground`}
                                        >
                                            {formatDateTime(log.created_at)}
                                        </td>
                                        <td className={tableStyles.td}>
                                            <p className="font-semibold">
                                                {auditActionLabels[
                                                    log.action
                                                ] ?? log.action}
                                            </p>
                                            <code className="font-mono text-xs text-muted-foreground">
                                                {log.action}
                                            </code>
                                        </td>
                                        <td className={tableStyles.td}>
                                            {log.actor ?? '—'}
                                        </td>
                                        <td className={tableStyles.td}>
                                            {log.subject ?? '—'}
                                        </td>
                                        <td
                                            className={`${tableStyles.td} font-mono text-xs`}
                                        >
                                            {log.ip_address ?? '—'}
                                        </td>
                                        <td className={tableStyles.td}>
                                            {log.changes ? (
                                                <code className="block max-w-sm rounded-lg bg-muted px-2.5 py-1.5 font-mono text-xs break-all text-muted-foreground">
                                                    {JSON.stringify(
                                                        log.changes,
                                                    )}
                                                </code>
                                            ) : (
                                                '—'
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {logs.last_page > 1 && (
                    <div className="border-t px-6 py-4">
                        <Pagination page={logs} />
                    </div>
                )}
            </Panel>
        </>
    );
}
