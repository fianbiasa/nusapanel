import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Pagination } from '@/components/pagination';
import { formatDateTime } from '@/lib/format';
import { index } from '@/routes/admin/audit-logs';
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
            <Head title="Audit log" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Audit log"
                    description="Who did what, when, and from where. Entries cannot be edited."
                />

                {logs.data.length === 0 ? (
                    <p className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                        No audit entries yet.
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                <tr>
                                    <th className="px-4 py-2 font-medium">
                                        Time
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Action
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Actor
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Subject
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        IP address
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Details
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {logs.data.map((log) => (
                                    <tr key={log.id} className="align-top">
                                        <td className="px-4 py-3 whitespace-nowrap">
                                            {formatDateTime(log.created_at)}
                                        </td>
                                        <td className="px-4 py-3">
                                            <code className="font-mono text-xs">
                                                {log.action}
                                            </code>
                                        </td>
                                        <td className="px-4 py-3">
                                            {log.actor ?? '—'}
                                        </td>
                                        <td className="px-4 py-3">
                                            {log.subject ?? '—'}
                                        </td>
                                        <td className="px-4 py-3 font-mono text-xs">
                                            {log.ip_address ?? '—'}
                                        </td>
                                        <td className="px-4 py-3">
                                            {log.changes ? (
                                                <code className="font-mono text-xs break-all text-muted-foreground">
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

                <Pagination page={logs} />
            </div>
        </>
    );
}

AuditLogsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Audit log',
            href: index(),
        },
    ],
};
