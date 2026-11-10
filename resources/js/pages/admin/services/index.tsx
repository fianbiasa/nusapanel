import { Head, router, usePoll } from '@inertiajs/react';
import {
    FileCog,
    Play,
    RefreshCw,
    RotateCw,
    ShieldAlert,
    Square,
} from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/components/page-header';
import { Panel, tableStyles } from '@/components/panel';
import { StatusBadge } from '@/components/status-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { formatBytes, formatDateTime, formatRelative } from '@/lib/format';
import { act, rebuild } from '@/routes/admin/services';

type ServiceAction = 'start' | 'stop' | 'restart' | 'reload';

type ServiceStatus = {
    unit: string;
    label: string;
    can_stop: boolean;
    can_control: boolean;
    active: string;
    sub: string;
    enabled: boolean;
    since: number;
    memory: number;
    main_pid: number;
};

type WatchdogEvent = {
    time: string;
    unit: string;
    label: string;
    outcome: 'restarted' | 'restart_failed' | 'gave_up';
    message: string;
};

type Report = {
    services: ServiceStatus[];
    pools: { total: number; running: number; failed: string[] };
    events: WatchdogEvent[];
};

type Props = {
    report: Report | null;
    error: string | null;
};

type Pending = { service: ServiceStatus; action: ServiceAction };

const actionLabels: Record<ServiceAction, string> = {
    start: 'Start',
    stop: 'Stop',
    restart: 'Restart',
    reload: 'Reload',
};

const outcomeLabels: Record<WatchdogEvent['outcome'], string> = {
    restarted: 'Dinyalakan lagi',
    restart_failed: 'Gagal dinyalakan',
    gave_up: 'Dibiarkan',
};

function serviceBadge(service: ServiceStatus) {
    switch (service.active) {
        case 'active':
        case 'reloading':
            return <StatusBadge status="active" label="Berjalan" />;
        case 'activating':
            return <StatusBadge status="running" label="Menyala…" />;
        case 'deactivating':
            return <StatusBadge status="removing" label="Berhenti…" />;
        case 'failed':
            return <StatusBadge status="failed" label="Gagal" />;
        default:
            return <StatusBadge status="cancelled" label="Berhenti" />;
    }
}

/** What a confirmation says before an action runs. */
function consequence({ service, action }: Pending): string {
    switch (action) {
        case 'stop':
            return `${service.label} berhenti dan tidak menyala lagi saat server di-boot ulang, sampai di-Start dari sini. Watchdog juga tidak akan menyalakannya.`;
        case 'restart':
            return `${service.label} berhenti sebentar lalu menyala lagi. Koneksi yang sedang berjalan bisa terputus.`;
        case 'reload':
            return `${service.label} memuat ulang konfigurasinya tanpa memutus koneksi.`;
        default:
            return `${service.label} dinyalakan dan menyala otomatis lagi saat boot.`;
    }
}

function since(service: ServiceStatus): string {
    const running =
        service.active === 'active' || service.active === 'reloading';

    if (!running || service.since <= 0) {
        return '—';
    }

    return formatRelative(new Date(service.since * 1000).toISOString());
}

export default function ServicesIndex({ report, error }: Props) {
    const [pending, setPending] = useState<Pending | null>(null);
    const [processing, setProcessing] = useState(false);
    const [actionError, setActionError] = useState<string | null>(null);
    const [confirmingRebuild, setConfirmingRebuild] = useState(false);
    const [rebuilding, setRebuilding] = useState(false);

    usePoll(10_000, { only: ['report', 'error'] });

    const runRebuild = () => {
        setRebuilding(true);
        router.post(
            rebuild().url,
            {},
            {
                preserveScroll: true,
                onSuccess: () => setConfirmingRebuild(false),
                onFinish: () => setRebuilding(false),
            },
        );
    };

    const run = () => {
        if (!pending) {
            return;
        }

        setProcessing(true);
        setActionError(null);
        router.post(
            act({ unit: pending.service.unit, action: pending.action }).url,
            {},
            {
                preserveScroll: true,
                onSuccess: () => setPending(null),
                onError: (errors) =>
                    setActionError(Object.values(errors)[0] ?? null),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <>
            <Head title="Services" />

            <PageHeader
                title="Services"
                description="Service sistem yang dipakai server ini. Watchdog agent menyalakan lagi otomatis service yang mati, tiap menit."
                actions={
                    <Button
                        variant="secondary"
                        onClick={() => setConfirmingRebuild(true)}
                    >
                        <FileCog />
                        Tulis ulang konfigurasi
                    </Button>
                }
            />

            {error !== null && (
                <Alert variant="destructive">
                    <ShieldAlert />
                    <AlertDescription>{error}</AlertDescription>
                </Alert>
            )}

            {report !== null && (
                <>
                    <Panel title="Service sistem" flush>
                        <div className="overflow-x-auto">
                            <table className={tableStyles.table}>
                                <thead className={tableStyles.head}>
                                    <tr>
                                        <th className={tableStyles.th}>
                                            Service
                                        </th>
                                        <th className={tableStyles.th}>
                                            Status
                                        </th>
                                        <th
                                            className={`${tableStyles.th} hidden md:table-cell`}
                                        >
                                            Sejak
                                        </th>
                                        <th
                                            className={`${tableStyles.th} hidden text-right sm:table-cell`}
                                        >
                                            Memori
                                        </th>
                                        <th className={tableStyles.th}>
                                            <span className="sr-only">
                                                Aksi
                                            </span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {report.services.map((service) => (
                                        <ServiceRow
                                            key={service.unit}
                                            service={service}
                                            onAction={(action) =>
                                                setPending({ service, action })
                                            }
                                        />
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </Panel>

                    <div className="grid gap-6 lg:grid-cols-2">
                        <Panel
                            title="PHP website"
                            description="Satu pool PHP-FPM per akun dan versi PHP."
                        >
                            <p className="text-3xl font-extrabold tabular-nums">
                                {report.pools.running}
                                <span className="text-base font-semibold text-muted-foreground">
                                    {' '}
                                    dari {report.pools.total} berjalan
                                </span>
                            </p>
                            {report.pools.failed.length > 0 && (
                                <p className="mt-3 text-sm text-destructive-foreground">
                                    Gagal:{' '}
                                    <span className="font-mono text-xs">
                                        {report.pools.failed.join(', ')}
                                    </span>
                                </p>
                            )}
                            <p className="mt-3 text-sm text-muted-foreground">
                                Pool akun yang di-suspend memang berhenti.
                                Watchdog menyalakan lagi pool lain yang mati.
                            </p>
                        </Panel>

                        <Panel
                            title="Watchdog"
                            description="Kejadian terbaru sejak agent terakhir menyala."
                        >
                            {report.events.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    Belum ada service yang perlu dinyalakan
                                    lagi.
                                </p>
                            ) : (
                                <ul className="divide-y text-sm">
                                    {report.events.map((event) => (
                                        <li
                                            key={`${event.time}-${event.unit}`}
                                            className="flex flex-wrap items-center gap-x-3 gap-y-1 py-2.5"
                                        >
                                            <StatusBadge
                                                status={
                                                    event.outcome ===
                                                    'restarted'
                                                        ? 'active'
                                                        : 'failed'
                                                }
                                                label={
                                                    outcomeLabels[event.outcome]
                                                }
                                            />
                                            <span className="font-semibold">
                                                {event.label}
                                            </span>
                                            <span
                                                className="text-muted-foreground"
                                                title={formatDateTime(
                                                    event.time,
                                                )}
                                            >
                                                {formatRelative(event.time)}
                                            </span>
                                            {event.outcome !== 'restarted' && (
                                                <span className="basis-full font-mono text-xs break-all text-muted-foreground">
                                                    {event.message}
                                                </span>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Panel>
                    </div>
                </>
            )}

            <Dialog
                open={pending !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setPending(null);
                        setActionError(null);
                    }
                }}
            >
                <DialogContent className="rounded-2xl">
                    {pending && (
                        <>
                            <DialogHeader>
                                <DialogTitle>
                                    {actionLabels[pending.action]}{' '}
                                    {pending.service.label}?
                                </DialogTitle>
                                <DialogDescription>
                                    {consequence(pending)}
                                </DialogDescription>
                            </DialogHeader>
                            {actionError && (
                                <Alert variant="destructive">
                                    <AlertDescription className="break-all">
                                        {actionError}
                                    </AlertDescription>
                                </Alert>
                            )}
                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button type="button" variant="secondary">
                                        Batal
                                    </Button>
                                </DialogClose>
                                <Button
                                    variant={
                                        pending.action === 'stop'
                                            ? 'destructive'
                                            : 'default'
                                    }
                                    disabled={processing}
                                    onClick={run}
                                >
                                    {processing && <Spinner />}
                                    {actionLabels[pending.action]}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </DialogContent>
            </Dialog>

            <Dialog
                open={confirmingRebuild}
                onOpenChange={setConfirmingRebuild}
            >
                <DialogContent className="rounded-2xl">
                    <DialogHeader>
                        <DialogTitle>
                            Tulis ulang konfigurasi server?
                        </DialogTitle>
                        <DialogDescription>
                            Konfigurasi Nginx, Apache dan PHP setiap website,
                            kuota disk, crontab dan folder FTP setiap akun
                            ditulis ulang dari database panel. Pakai ini kalau
                            ada file konfigurasi yang terhapus atau diubah
                            manual. Website tetap jalan selama prosesnya.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Batal
                            </Button>
                        </DialogClose>
                        <Button disabled={rebuilding} onClick={runRebuild}>
                            {rebuilding && <Spinner />}
                            Tulis ulang
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function ServiceRow({
    service,
    onAction,
}: {
    service: ServiceStatus;
    onAction: (action: ServiceAction) => void;
}) {
    const running =
        service.active === 'active' || service.active === 'reloading';

    return (
        <tr className={tableStyles.row}>
            <td className={tableStyles.td}>
                <div className="font-semibold">{service.label}</div>
                <div className="font-mono text-xs text-muted-foreground">
                    {service.unit}
                    {!service.enabled && ' · tidak menyala saat boot'}
                </div>
            </td>
            <td className={tableStyles.td}>{serviceBadge(service)}</td>
            <td
                className={`${tableStyles.td} hidden whitespace-nowrap text-muted-foreground md:table-cell`}
            >
                {since(service)}
            </td>
            <td
                className={`${tableStyles.td} hidden text-right whitespace-nowrap text-muted-foreground tabular-nums sm:table-cell`}
            >
                {service.memory > 0 ? formatBytes(service.memory) : '—'}
            </td>
            <td className="px-4 py-2">
                {!service.can_control ? (
                    <span className="block text-right text-xs text-muted-foreground">
                        hanya status
                    </span>
                ) : (
                    <div className="flex justify-end gap-1">
                        {running ? (
                            <>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    title="Reload"
                                    aria-label="Reload"
                                    onClick={() => onAction('reload')}
                                >
                                    <RefreshCw />
                                    <span className="hidden xl:inline">
                                        Reload
                                    </span>
                                </Button>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    title="Restart"
                                    aria-label="Restart"
                                    onClick={() => onAction('restart')}
                                >
                                    <RotateCw />
                                    <span className="hidden xl:inline">
                                        Restart
                                    </span>
                                </Button>
                                {service.can_stop && (
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        className="text-destructive-foreground"
                                        title="Stop"
                                        aria-label="Stop"
                                        onClick={() => onAction('stop')}
                                    >
                                        <Square />
                                        <span className="hidden xl:inline">
                                            Stop
                                        </span>
                                    </Button>
                                )}
                            </>
                        ) : (
                            <Button
                                size="sm"
                                title="Start"
                                aria-label="Start"
                                onClick={() => onAction('start')}
                            >
                                <Play />
                                <span className="hidden xl:inline">Start</span>
                            </Button>
                        )}
                    </div>
                )}
            </td>
        </tr>
    );
}
