import { Deferred, Head, usePage } from '@inertiajs/react';
import { AlertCircle } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { formatUptime, percentage } from '@/lib/format';
import { dashboard } from '@/routes';
import type { Role } from '@/types';

type SystemInfo = {
    hostname: string;
    os: { name: string; version: string; kernel: string };
    cpu: { model: string; cores: number };
    load: [number, number, number];
    memory: { total_mb: number; used_mb: number };
    disk: { mount: string; total_gb: number; used_gb: number };
    uptime_seconds: number;
    agent_version: string;
    simulated?: boolean;
};

type SystemInfoResult = {
    data: SystemInfo | null;
    error: string | null;
};

type Props = {
    systemInfo?: SystemInfoResult;
};

const roleLabels: Record<Role, string> = {
    admin: 'Admin',
    reseller: 'Reseller',
    user: 'User',
};

export default function Dashboard({ systemInfo }: Props) {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto p-4">
                <Card>
                    <CardHeader>
                        <CardTitle>Welcome, {auth.user.name}</CardTitle>
                        <CardDescription>
                            Signed in as{' '}
                            <span className="font-medium text-foreground">
                                {auth.user.username}
                            </span>
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex gap-2">
                        <Badge variant="secondary">
                            {roleLabels[auth.user.role]}
                        </Badge>
                        <Badge variant="outline">{auth.user.status}</Badge>
                    </CardContent>
                </Card>

                {auth.user.role === 'admin' && (
                    <section className="space-y-3">
                        <h2 className="text-lg font-semibold tracking-tight">
                            Server
                        </h2>
                        <Deferred
                            data="systemInfo"
                            fallback={<SystemInfoSkeleton />}
                        >
                            <SystemInfoPanel result={systemInfo} />
                        </Deferred>
                    </section>
                )}
            </div>
        </>
    );
}

function SystemInfoPanel({ result }: { result?: SystemInfoResult }) {
    if (!result) {
        return null;
    }

    if (result.error !== null || result.data === null) {
        return (
            <Alert variant="destructive">
                <AlertCircle />
                <AlertTitle>Server information unavailable</AlertTitle>
                <AlertDescription>
                    {result.error ?? 'The agent returned no data.'}
                </AlertDescription>
            </Alert>
        );
    }

    const info = result.data;
    const memoryUsed = percentage(info.memory.used_mb, info.memory.total_mb);
    const diskUsed = percentage(info.disk.used_gb, info.disk.total_gb);

    return (
        <div className="space-y-3">
            {info.simulated && (
                <Badge variant="outline">
                    Simulated agent — local development
                </Badge>
            )}
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    title="Host"
                    value={info.hostname}
                    detail={`${info.os.name} ${info.os.version} · kernel ${info.os.kernel}`}
                />
                <StatCard
                    title="CPU"
                    value={`${info.cpu.cores} cores`}
                    detail={`Load ${info.load.map((load) => load.toFixed(2)).join(' / ')}`}
                />
                <StatCard
                    title="Memory"
                    value={`${memoryUsed}%`}
                    detail={`${(info.memory.used_mb / 1024).toFixed(1)} of ${(info.memory.total_mb / 1024).toFixed(1)} GB`}
                    meter={memoryUsed}
                />
                <StatCard
                    title={`Disk ${info.disk.mount}`}
                    value={`${diskUsed}%`}
                    detail={`${info.disk.used_gb} of ${info.disk.total_gb} GB`}
                    meter={diskUsed}
                />
            </div>
            <p className="text-sm text-muted-foreground">
                Uptime {formatUptime(info.uptime_seconds)} · agent{' '}
                {info.agent_version}
            </p>
        </div>
    );
}

function StatCard({
    title,
    value,
    detail,
    meter,
}: {
    title: string;
    value: string;
    detail: string;
    meter?: number;
}) {
    return (
        <Card className="gap-2">
            <CardHeader>
                <CardDescription>{title}</CardDescription>
                <CardTitle className="truncate text-xl">{value}</CardTitle>
            </CardHeader>
            <CardContent className="space-y-2">
                {meter !== undefined && (
                    <div
                        role="meter"
                        aria-label={title}
                        aria-valuemin={0}
                        aria-valuemax={100}
                        aria-valuenow={meter}
                        className="h-1.5 overflow-hidden rounded-full bg-muted"
                    >
                        <div
                            className="h-full rounded-full bg-primary"
                            style={{ width: `${meter}%` }}
                        />
                    </div>
                )}
                <p className="truncate text-xs text-muted-foreground">
                    {detail}
                </p>
            </CardContent>
        </Card>
    );
}

function SystemInfoSkeleton() {
    return (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            {[0, 1, 2, 3].map((index) => (
                <Skeleton key={index} className="h-28 rounded-xl" />
            ))}
        </div>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
