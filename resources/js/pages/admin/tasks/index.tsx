import { Head } from '@inertiajs/react';
import { ListChecks } from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { Panel, tableStyles } from '@/components/panel';
import { StatusBadge } from '@/components/status-badge';
import { formatDateTime, taskActionLabels } from '@/lib/format';
import type { Paginated } from '@/types';

type TaskStatus = 'queued' | 'running' | 'success' | 'failed' | 'cancelled';

type TaskRow = {
    id: string;
    action: string;
    status: TaskStatus;
    progress: number;
    account: string | null;
    actor: string | null;
    error: string | null;
    created_at: string;
    finished_at: string | null;
};

export default function TasksIndex({ tasks }: { tasks: Paginated<TaskRow> }) {
    return (
        <>
            <Head title="Tasks" />

            <PageHeader
                title="Tasks"
                description="Semua perubahan yang diminta panel ke agent server."
            />

            <Panel
                flush
                title="Riwayat task"
                description={`${tasks.total} task`}
            >
                {tasks.data.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 px-6 pt-4 pb-10 text-center">
                        <span className="flex size-14 items-center justify-center rounded-full bg-accent text-accent-foreground">
                            <ListChecks className="size-6" />
                        </span>
                        <p className="max-w-sm text-sm text-muted-foreground">
                            Belum ada task. Task muncul di sini setiap kali
                            akun, domain, atau layanan diubah.
                        </p>
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className={tableStyles.table}>
                            <thead className={tableStyles.head}>
                                <tr>
                                    <th className={tableStyles.th}>Aksi</th>
                                    <th className={tableStyles.th}>Status</th>
                                    <th className={tableStyles.th}>Akun</th>
                                    <th className={tableStyles.th}>Oleh</th>
                                    <th className={tableStyles.th}>Dibuat</th>
                                    <th className={tableStyles.th}>Selesai</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {tasks.data.map((task) => (
                                    <tr
                                        key={task.id}
                                        className={`${tableStyles.row} align-top`}
                                    >
                                        <td className={tableStyles.td}>
                                            <p className="font-semibold">
                                                {taskActionLabels[
                                                    task.action
                                                ] ?? task.action}
                                            </p>
                                            <code className="font-mono text-xs text-muted-foreground">
                                                {task.action}
                                            </code>
                                            {task.error && (
                                                <p className="mt-1.5 max-w-md rounded-lg bg-destructive/8 px-2.5 py-1.5 text-xs text-destructive-foreground">
                                                    {task.error}
                                                </p>
                                            )}
                                        </td>
                                        <td
                                            className={`${tableStyles.td} whitespace-nowrap`}
                                        >
                                            <StatusBadge status={task.status} />
                                            {task.status === 'running' && (
                                                <span className="ml-2 text-xs text-muted-foreground">
                                                    {task.progress}%
                                                </span>
                                            )}
                                        </td>
                                        <td className={tableStyles.td}>
                                            {task.account ?? (
                                                <span className="text-muted-foreground">
                                                    —
                                                </span>
                                            )}
                                        </td>
                                        <td
                                            className={`${tableStyles.td} text-muted-foreground`}
                                        >
                                            {task.actor ?? 'sistem'}
                                        </td>
                                        <td
                                            className={`${tableStyles.td} whitespace-nowrap text-muted-foreground`}
                                        >
                                            {formatDateTime(task.created_at)}
                                        </td>
                                        <td
                                            className={`${tableStyles.td} whitespace-nowrap text-muted-foreground`}
                                        >
                                            {task.finished_at
                                                ? formatDateTime(
                                                      task.finished_at,
                                                  )
                                                : '—'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {tasks.last_page > 1 && (
                    <div className="border-t px-6 py-4">
                        <Pagination page={tasks} />
                    </div>
                )}
            </Panel>
        </>
    );
}
