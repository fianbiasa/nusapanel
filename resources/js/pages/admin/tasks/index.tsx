import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { formatDateTime } from '@/lib/format';
import { index } from '@/routes/admin/tasks';
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

const statusVariants: Record<
    TaskStatus,
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    queued: 'outline',
    running: 'secondary',
    success: 'default',
    failed: 'destructive',
    cancelled: 'outline',
};

export default function TasksIndex({ tasks }: { tasks: Paginated<TaskRow> }) {
    return (
        <>
            <Head title="Tasks" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Tasks"
                    description="Every change the panel has asked the server agent to make."
                />

                {tasks.data.length === 0 ? (
                    <p className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                        No tasks yet. Tasks appear here when accounts, domains,
                        or services are changed.
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                <tr>
                                    <th className="px-4 py-2 font-medium">
                                        Action
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Status
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Account
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Requested by
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Created
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Finished
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {tasks.data.map((task) => (
                                    <tr key={task.id} className="align-top">
                                        <td className="px-4 py-3">
                                            <code className="font-mono text-xs">
                                                {task.action}
                                            </code>
                                            {task.error && (
                                                <p className="mt-1 max-w-md text-xs text-destructive">
                                                    {task.error}
                                                </p>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 whitespace-nowrap">
                                            <Badge
                                                variant={
                                                    statusVariants[task.status]
                                                }
                                            >
                                                {task.status}
                                            </Badge>
                                            {task.status === 'running' && (
                                                <span className="ml-2 text-xs text-muted-foreground">
                                                    {task.progress}%
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            {task.account ?? '—'}
                                        </td>
                                        <td className="px-4 py-3">
                                            {task.actor ?? 'system'}
                                        </td>
                                        <td className="px-4 py-3 whitespace-nowrap">
                                            {formatDateTime(task.created_at)}
                                        </td>
                                        <td className="px-4 py-3 whitespace-nowrap">
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

                <Pagination page={tasks} />
            </div>
        </>
    );
}

TasksIndex.layout = {
    breadcrumbs: [
        {
            title: 'Tasks',
            href: index(),
        },
    ],
};
