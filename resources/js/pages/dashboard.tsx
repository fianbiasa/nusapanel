import { Deferred, Head, Link, usePage } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    AlertTriangle,
    CircleCheck,
    Cpu,
    Globe,
    HardDrive,
    KeyRound,
    ListChecks,
    LogIn,
    Gauge,
    Plus,
    Server,
    ShieldOff,
    ShieldCheck,
    Trash2,
    UserMinus,
    UserPlus,
    Users,
} from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { Panel, PanelLink } from '@/components/panel';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import {
    auditActionLabels,
    formatRelative,
    formatUptime,
    percentage,
} from '@/lib/format';
import { cn } from '@/lib/utils';
import { create as accountsCreate } from '@/routes/admin/accounts';
import { index as auditLogsIndex } from '@/routes/admin/audit-logs';
import { index as tasksIndex } from '@/routes/admin/tasks';
import { index as domainsIndex } from '@/routes/domains';

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

type SystemInfoResult = { data: SystemInfo | null; error: string | null };

type StatusCounts = Record<string, number> & { total: number };

type Activity = {
    id: number;
    action: string;
    actor: string | null;
    created_at: string | null;
};

type DomainSummary = {
    id: number;
    name: string;
    display_name: string;
    status: string;
    php_version: string;
    is_primary: boolean;
};

type Props = {
    stats?: {
        accounts?: StatusCounts;
        domains?: StatusCounts;
        tasks?: { active: number; failed_24h: number; total_24h: number };
    };
    recentActivity?: Activity[];
    domains?: DomainSummary[];
    systemInfo?: SystemInfoResult;
};

export default function Dashboard({
    stats,
    recentActivity = [],
    domains = [],
    systemInfo,
}: Props) {
    const { auth } = usePage().props;
    const firstName = auth.user.name.split(' ')[0];
    const isAdmin = auth.user.role === 'admin';
    const isHosting = auth.user.role === 'user';

    return (
        <>
            <Head title="Dashboard" />

            <PageHeader
                title={<>Halo, {firstName} 👋</>}
                description={
                    isAdmin
                        ? 'Berikut ringkasan server dan layanan di Nusapanel.'
                        : 'Berikut ringkasan website kamu di Nusapanel.'
                }
                actions={
                    isAdmin ? (
                        <Button asChild size="lg" className="rounded-xl">
                            <Link href={accountsCreate()}>
                                <Plus /> Buat akun
                            </Link>
                        </Button>
                    ) : isHosting ? (
                        <Button asChild size="lg" className="rounded-xl">
                            <Link href={domainsIndex()}>
                                <Plus /> Tambah domain
                            </Link>
                        </Button>
                    ) : null
                }
            />

            {isAdmin && stats?.accounts && stats.domains && stats.tasks && (
                <>
                    <section className="grid grid-cols-2 gap-3 sm:gap-5 xl:grid-cols-4">
                        <StatCard
                            icon={Users}
                            tone="blue"
                            value={stats.accounts.total}
                            label="Akun Hosting"
                            footer={`${stats.accounts.active ?? 0} aktif · ${stats.accounts.suspended ?? 0} suspend`}
                            ratio={ratio(
                                stats.accounts.active,
                                stats.accounts.total,
                            )}
                        />
                        <StatCard
                            icon={Globe}
                            tone="purple"
                            value={stats.domains.total}
                            label="Total Domain"
                            footer={`${stats.domains.active ?? 0} aktif · ${stats.domains.failed ?? 0} gagal`}
                            ratio={ratio(
                                stats.domains.active,
                                stats.domains.total,
                            )}
                        />
                        <StatCard
                            icon={ListChecks}
                            tone="teal"
                            value={stats.tasks.active}
                            label="Task Berjalan"
                            footer={`${stats.tasks.total_24h} task dalam 24 jam`}
                        />
                        <StatCard
                            icon={AlertTriangle}
                            tone="orange"
                            value={stats.tasks.failed_24h}
                            label="Task Gagal (24 jam)"
                            footer={
                                stats.tasks.failed_24h === 0
                                    ? 'Semua task berjalan lancar'
                                    : 'Cek detailnya di halaman Tasks'
                            }
                            footerTone={
                                stats.tasks.failed_24h === 0 ? 'good' : 'bad'
                            }
                        />
                    </section>

                    <section className="grid gap-5 xl:grid-cols-3">
                        <Panel
                            title="Server"
                            className="xl:col-span-2"
                            action={
                                <PanelLink href={tasksIndex()}>
                                    Lihat task
                                </PanelLink>
                            }
                        >
                            <Deferred
                                data="systemInfo"
                                fallback={<ServerSkeleton />}
                            >
                                <ServerPanel result={systemInfo} />
                            </Deferred>
                        </Panel>

                        <Panel
                            title="Aktivitas Terbaru"
                            action={
                                <PanelLink href={auditLogsIndex()}>
                                    Lihat semua
                                </PanelLink>
                            }
                        >
                            <ActivityList items={recentActivity} />
                        </Panel>
                    </section>
                </>
            )}

            {isHosting && stats?.domains && (
                <>
                    <section className="grid grid-cols-2 gap-3 sm:gap-5 xl:grid-cols-4">
                        <StatCard
                            icon={Globe}
                            tone="blue"
                            value={stats.domains.total}
                            label="Total Domain"
                        />
                        <StatCard
                            icon={CircleCheck}
                            tone="teal"
                            value={stats.domains.active ?? 0}
                            label="Domain Aktif"
                            ratio={ratio(
                                stats.domains.active,
                                stats.domains.total,
                            )}
                        />
                        <StatCard
                            icon={Server}
                            tone="purple"
                            value={stats.domains.pending ?? 0}
                            label="Sedang Disiapkan"
                        />
                        <StatCard
                            icon={AlertTriangle}
                            tone="orange"
                            value={stats.domains.failed ?? 0}
                            label="Gagal"
                        />
                    </section>

                    <Panel
                        title="Domain Kamu"
                        action={
                            <PanelLink href={domainsIndex()}>
                                Kelola domain
                            </PanelLink>
                        }
                    >
                        <DomainList domains={domains} />
                    </Panel>
                </>
            )}
        </>
    );
}

function ratio(part: number | undefined, total: number): number | undefined {
    return total > 0 ? Math.round(((part ?? 0) / total) * 100) : undefined;
}

const toneStyles = {
    blue: { icon: 'bg-chart-1/12 text-chart-1', bar: 'bg-chart-1' },
    purple: { icon: 'bg-chart-2/12 text-chart-2', bar: 'bg-chart-2' },
    teal: { icon: 'bg-chart-3/12 text-chart-3', bar: 'bg-chart-3' },
    orange: { icon: 'bg-chart-4/12 text-chart-4', bar: 'bg-chart-4' },
};

function StatCard({
    icon: Icon,
    tone,
    value,
    label,
    footer,
    footerTone,
    ratio: share,
}: {
    icon: LucideIcon;
    tone: keyof typeof toneStyles;
    value: number;
    label: string;
    footer?: string;
    footerTone?: 'good' | 'bad';
    ratio?: number;
}) {
    return (
        <article className="flex flex-col gap-4 rounded-2xl border bg-card p-4 shadow-card sm:p-5">
            <div className="flex flex-col items-start gap-3 sm:flex-row sm:items-center sm:gap-4">
                <span
                    className={cn(
                        'flex size-11 shrink-0 items-center justify-center rounded-full sm:size-14',
                        toneStyles[tone].icon,
                    )}
                >
                    <Icon className="size-6" strokeWidth={1.8} />
                </span>
                <div className="min-w-0">
                    <div className="text-[28px] leading-none font-extrabold tracking-tight">
                        {value.toLocaleString('id-ID')}
                    </div>
                    <div className="mt-1.5 truncate text-sm text-muted-foreground">
                        {label}
                    </div>
                </div>
            </div>
            {(footer !== undefined || share !== undefined) && (
                <div className="space-y-2">
                    {share !== undefined && (
                        <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                            <div
                                className={cn(
                                    'h-full rounded-full',
                                    toneStyles[tone].bar,
                                )}
                                style={{ width: `${share}%` }}
                            />
                        </div>
                    )}
                    {footer && (
                        <p
                            className={cn(
                                'truncate text-[13px] font-medium',
                                footerTone === 'good' && 'text-success',
                                footerTone === 'bad' &&
                                    'text-destructive-foreground',
                                footerTone === undefined &&
                                    'text-muted-foreground',
                            )}
                        >
                            {footer}
                        </p>
                    )}
                </div>
            )}
        </article>
    );
}

function ServerPanel({ result }: { result?: SystemInfoResult }) {
    if (!result) {
        return null;
    }

    if (result.error !== null || result.data === null) {
        return (
            <div className="flex items-start gap-3 rounded-xl bg-destructive/8 p-4 text-sm">
                <AlertTriangle className="mt-0.5 size-5 shrink-0 text-destructive-foreground" />
                <div>
                    <p className="font-semibold text-destructive-foreground">
                        Info server tidak tersedia
                    </p>
                    <p className="text-muted-foreground">
                        {result.error ?? 'Agent tidak mengirim data.'}
                    </p>
                </div>
            </div>
        );
    }

    const info = result.data;
    const cpu = Math.min(
        100,
        Math.round((info.load[0] / Math.max(1, info.cpu.cores)) * 100),
    );
    const memory = percentage(info.memory.used_mb, info.memory.total_mb);
    const disk = percentage(info.disk.used_gb, info.disk.total_gb);

    return (
        <div className="flex flex-col gap-6">
            <dl className="grid grid-cols-2 gap-x-6 gap-y-4 rounded-xl bg-muted/60 p-4 text-sm md:grid-cols-4">
                <Fact label="Hostname" value={info.hostname} />
                <Fact
                    label="Sistem"
                    value={`${info.os.name} ${info.os.version}`}
                    title={`kernel ${info.os.kernel}`}
                />
                <Fact
                    label="Uptime"
                    value={formatUptime(info.uptime_seconds)}
                />
                <Fact label="Agent" value={info.agent_version} />
            </dl>

            <div className="grid gap-4 sm:grid-cols-3">
                <ResourceRing
                    icon={Cpu}
                    label="CPU"
                    value={cpu}
                    detail={`${info.cpu.cores} core · load ${info.load[0].toFixed(2)}`}
                />
                <ResourceRing
                    icon={Gauge}
                    label="Memori"
                    value={memory}
                    detail={`${(info.memory.used_mb / 1024).toFixed(1)} / ${(info.memory.total_mb / 1024).toFixed(1)} GB`}
                />
                <ResourceRing
                    icon={HardDrive}
                    label={`Disk ${info.disk.mount}`}
                    value={disk}
                    detail={`${info.disk.used_gb} / ${info.disk.total_gb} GB`}
                />
            </div>

            {info.simulated && (
                <p className="text-xs text-muted-foreground">
                    Data simulasi: agent berjalan dalam mode pengembangan lokal.
                </p>
            )}
        </div>
    );
}

function Fact({
    label,
    value,
    title,
}: {
    label: string;
    value: string;
    title?: string;
}) {
    return (
        <div className="min-w-0">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="truncate font-semibold" title={title ?? value}>
                {value}
            </dd>
        </div>
    );
}

function ResourceRing({
    icon: Icon,
    label,
    value,
    detail,
}: {
    icon: LucideIcon;
    label: string;
    value: number;
    detail: string;
}) {
    const radius = 42;
    const circumference = 2 * Math.PI * radius;
    const color =
        value >= 90
            ? 'text-destructive'
            : value >= 75
              ? 'text-warning'
              : 'text-primary';

    return (
        <div className="flex flex-col items-center gap-3 rounded-xl border p-5 text-center">
            <div className="relative size-28 shrink-0">
                <svg
                    viewBox="0 0 100 100"
                    className="size-full -rotate-90"
                    role="meter"
                    aria-label={label}
                    aria-valuemin={0}
                    aria-valuemax={100}
                    aria-valuenow={value}
                >
                    <circle
                        cx="50"
                        cy="50"
                        r={radius}
                        fill="none"
                        strokeWidth="10"
                        className="stroke-muted"
                    />
                    <circle
                        cx="50"
                        cy="50"
                        r={radius}
                        fill="none"
                        strokeWidth="10"
                        strokeLinecap="round"
                        stroke="currentColor"
                        strokeDasharray={circumference}
                        strokeDashoffset={circumference * (1 - value / 100)}
                        className={cn(
                            'transition-[stroke-dashoffset] duration-700',
                            color,
                        )}
                    />
                </svg>
                <span className="absolute inset-0 flex items-center justify-center text-xl font-extrabold">
                    {value}%
                </span>
            </div>
            <div className="w-full min-w-0 space-y-0.5">
                <p className="flex items-center justify-center gap-1.5 font-semibold">
                    <Icon className="size-4 text-muted-foreground" />
                    {label}
                </p>
                <p
                    className="truncate text-xs text-muted-foreground"
                    title={detail}
                >
                    {detail}
                </p>
            </div>
        </div>
    );
}

function ServerSkeleton() {
    return (
        <div className="flex flex-col gap-6">
            <Skeleton className="h-[76px] rounded-xl" />
            <div className="grid gap-4 sm:grid-cols-3">
                {[0, 1, 2].map((index) => (
                    <Skeleton key={index} className="h-32 rounded-xl" />
                ))}
            </div>
        </div>
    );
}

const activityIcons: Record<string, LucideIcon> = {
    'account.created': UserPlus,
    'account.deleted': UserMinus,
    'account.suspended': ShieldOff,
    'account.unsuspended': ShieldCheck,
    'account.password_reset': KeyRound,
    'auth.login': LogIn,
    'domain.created': Globe,
    'domain.deleted': Trash2,
};

function ActivityList({ items }: { items: Activity[] }) {
    if (items.length === 0) {
        return (
            <p className="py-6 text-center text-sm text-muted-foreground">
                Belum ada aktivitas.
            </p>
        );
    }

    return (
        <ul className="-my-1 divide-y">
            {items.map((item) => {
                const Icon = activityIcons[item.action] ?? CircleCheck;

                return (
                    <li key={item.id} className="flex items-center gap-3 py-3">
                        <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-accent text-accent-foreground">
                            <Icon className="size-4" />
                        </span>
                        <div className="min-w-0 flex-1">
                            <p className="truncate text-sm font-medium">
                                {auditActionLabels[item.action] ?? item.action}
                            </p>
                            <p className="truncate text-xs text-muted-foreground">
                                oleh {item.actor ?? 'sistem'}
                            </p>
                        </div>
                        {item.created_at && (
                            <time
                                dateTime={item.created_at}
                                className="shrink-0 text-xs text-muted-foreground"
                            >
                                {formatRelative(item.created_at)}
                            </time>
                        )}
                    </li>
                );
            })}
        </ul>
    );
}

function DomainList({ domains }: { domains: DomainSummary[] }) {
    if (domains.length === 0) {
        return (
            <div className="flex flex-col items-center gap-3 py-8 text-center">
                <span className="flex size-14 items-center justify-center rounded-full bg-accent text-accent-foreground">
                    <Globe className="size-6" />
                </span>
                <p className="text-sm text-muted-foreground">
                    Belum ada domain. Tambahkan domain pertama untuk mulai
                    membuat website.
                </p>
                <Button asChild className="rounded-xl">
                    <Link href={domainsIndex()}>
                        <Plus /> Tambah domain
                    </Link>
                </Button>
            </div>
        );
    }

    return (
        <ul className="-my-1 divide-y">
            {domains.map((domain) => (
                <li key={domain.id} className="flex items-center gap-3 py-3">
                    <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-accent text-accent-foreground">
                        <Globe className="size-4" />
                    </span>
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-semibold">
                            {domain.display_name}
                            {domain.is_primary && (
                                <span className="ml-2 text-xs font-medium text-muted-foreground">
                                    utama
                                </span>
                            )}
                        </p>
                        <p className="text-xs text-muted-foreground">
                            PHP {domain.php_version}
                        </p>
                    </div>
                    <StatusBadge status={domain.status} />
                </li>
            ))}
        </ul>
    );
}
