import { Head, router, usePoll } from '@inertiajs/react';
import { Cpu, RefreshCw, Search, Square } from 'lucide-react';
import { useMemo, useState } from 'react';
import { PageHeader } from '@/components/page-header';
import { Panel, tableStyles } from '@/components/panel';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatBytes, formatRelative } from '@/lib/format';
import { kill } from '@/routes/admin/processes';

type Process = {
    pid: number;
    ppid: number;
    user: string;
    uid: number;
    state: string;
    cpu: number;
    memory: number;
    command: string;
    started_at: string;
    start: string;
};

type UserUsage = {
    user: string;
    uid: number;
    processes: number;
    cpu: number;
    memory: number;
};

type Snapshot = {
    processes: Process[];
    users: UserUsage[];
    cpus: number;
    memory_total: number;
    total: number;
    truncated: boolean;
};

type Signal = 'TERM' | 'KILL';

type Props = {
    snapshot: { result: Snapshot | null; error: string | null };
    signals: Signal[];
};

const signalLabels: Record<Signal, { title: string; description: string }> = {
    TERM: {
        title: 'Hentikan',
        description:
            'Proses diminta berhenti (SIGTERM), sehingga bisa menyimpan pekerjaannya dulu.',
    },
    KILL: {
        title: 'Paksa hentikan',
        description:
            'Proses langsung dihentikan (SIGKILL) tanpa kesempatan menyimpan apa pun. Pakai kalau Hentikan tidak berhasil.',
    },
};

function percent(part: number, whole: number): string {
    return whole > 0
        ? `${((part / whole) * 100).toLocaleString('id-ID', { maximumFractionDigits: 1 })}%`
        : '';
}

export default function Processes({ snapshot, signals }: Props) {
    const [autoRefresh, setAutoRefresh] = useState(true);
    const [search, setSearch] = useState('');
    const [refreshing, setRefreshing] = useState(false);
    const [pending, setPending] = useState<{
        process: Process;
        signal: Signal;
    } | null>(null);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const { start, stop } = usePoll(
        5000,
        { only: ['snapshot'] },
        { autoStart: true },
    );

    const toggleAutoRefresh = (enabled: boolean) => {
        setAutoRefresh(enabled);

        if (enabled) {
            start();
        } else {
            stop();
        }
    };

    const refresh = () =>
        router.reload({
            only: ['snapshot'],
            onStart: () => setRefreshing(true),
            onFinish: () => setRefreshing(false),
        });

    const result = snapshot.result;
    const needle = search.trim().toLowerCase();

    const processes = useMemo(
        () =>
            (result?.processes ?? []).filter(
                (process) =>
                    needle === '' ||
                    process.command.toLowerCase().includes(needle) ||
                    process.user.toLowerCase().includes(needle) ||
                    String(process.pid) === needle,
            ),
        [result, needle],
    );

    const send = () => {
        if (pending === null) {
            return;
        }

        setProcessing(true);
        setError(null);

        router.post(
            kill().url,
            {
                pid: pending.process.pid,
                start: pending.process.start,
                signal: pending.signal,
                user: pending.process.user,
                command: pending.process.command,
            },
            {
                preserveScroll: true,
                onSuccess: () => setPending(null),
                onError: (errors) => setError(Object.values(errors)[0] ?? null),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <>
            <Head title="Proses" />

            <PageHeader
                title="Proses"
                description="Proses yang berjalan di server, yang paling sibuk di atas. CPU diukur selama setengah detik, dalam persen satu CPU."
                actions={
                    <div className="flex items-center gap-4">
                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="auto-refresh"
                                checked={autoRefresh}
                                onCheckedChange={(checked) =>
                                    toggleAutoRefresh(checked === true)
                                }
                            />
                            <Label htmlFor="auto-refresh">
                                Perbarui otomatis
                            </Label>
                        </div>
                        <Button
                            variant="secondary"
                            onClick={refresh}
                            disabled={refreshing}
                        >
                            <RefreshCw
                                className={
                                    refreshing ? 'animate-spin' : undefined
                                }
                            />
                            Perbarui
                        </Button>
                    </div>
                }
            />

            {snapshot.error !== null && (
                <Alert variant="destructive">
                    <AlertDescription>{snapshot.error}</AlertDescription>
                </Alert>
            )}

            {result && (
                <>
                    <Panel
                        title="Pemakaian per user"
                        description={`${result.total.toLocaleString('id-ID')} proses, ${result.cpus} CPU (100% = satu CPU penuh), RAM ${formatBytes(result.memory_total)}.`}
                        flush
                    >
                        <table className={tableStyles.table}>
                            <thead className={tableStyles.head}>
                                <tr>
                                    <th className={tableStyles.th}>User</th>
                                    <th
                                        className={`${tableStyles.th} text-right`}
                                    >
                                        Proses
                                    </th>
                                    <th
                                        className={`${tableStyles.th} text-right`}
                                    >
                                        CPU
                                    </th>
                                    <th
                                        className={`${tableStyles.th} text-right`}
                                    >
                                        RAM
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {result.users.slice(0, 15).map((usage) => (
                                    <tr
                                        key={usage.uid}
                                        className={tableStyles.row}
                                    >
                                        <td
                                            className={`${tableStyles.td} font-semibold`}
                                        >
                                            <button
                                                type="button"
                                                className="hover:underline"
                                                onClick={() =>
                                                    setSearch(usage.user)
                                                }
                                            >
                                                {usage.user}
                                            </button>
                                        </td>
                                        <td
                                            className={`${tableStyles.td} text-right`}
                                        >
                                            {usage.processes}
                                        </td>
                                        <td
                                            className={`${tableStyles.td} text-right font-mono`}
                                        >
                                            {usage.cpu.toLocaleString('id-ID')}%
                                        </td>
                                        <td
                                            className={`${tableStyles.td} text-right font-mono whitespace-nowrap`}
                                        >
                                            {formatBytes(usage.memory)}{' '}
                                            <span className="text-xs text-muted-foreground">
                                                {percent(
                                                    usage.memory,
                                                    result.memory_total,
                                                )}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </Panel>

                    <Panel
                        title="Semua proses"
                        description={
                            result.truncated
                                ? `${result.processes.length} proses tersibuk dari ${result.total}.`
                                : undefined
                        }
                        action={
                            <div className="relative w-64">
                                <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                    placeholder="Cari user, perintah, atau PID"
                                    aria-label="Cari proses"
                                    className="pl-9"
                                />
                            </div>
                        }
                        flush
                    >
                        <div className="overflow-x-auto">
                            <table className={tableStyles.table}>
                                <thead className={tableStyles.head}>
                                    <tr>
                                        <th className={tableStyles.th}>PID</th>
                                        <th className={tableStyles.th}>User</th>
                                        <th
                                            className={`${tableStyles.th} text-right`}
                                        >
                                            CPU
                                        </th>
                                        <th
                                            className={`${tableStyles.th} text-right`}
                                        >
                                            RAM
                                        </th>
                                        <th className={tableStyles.th}>
                                            Mulai
                                        </th>
                                        <th className={tableStyles.th}>
                                            Perintah
                                        </th>
                                        <th className={tableStyles.th} />
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {processes.map((process) => (
                                        <tr
                                            key={`${process.pid}-${process.start}`}
                                            className={tableStyles.row}
                                        >
                                            <td
                                                className={`${tableStyles.td} font-mono text-xs`}
                                            >
                                                {process.pid}
                                            </td>
                                            <td
                                                className={`${tableStyles.td} whitespace-nowrap`}
                                            >
                                                {process.user}
                                            </td>
                                            <td
                                                className={`${tableStyles.td} text-right font-mono text-xs`}
                                            >
                                                {process.cpu.toLocaleString(
                                                    'id-ID',
                                                )}
                                                %
                                            </td>
                                            <td
                                                className={`${tableStyles.td} text-right font-mono text-xs whitespace-nowrap`}
                                            >
                                                {formatBytes(process.memory)}
                                            </td>
                                            <td
                                                className={`${tableStyles.td} text-xs whitespace-nowrap text-muted-foreground`}
                                            >
                                                {formatRelative(
                                                    process.started_at,
                                                )}
                                            </td>
                                            <td
                                                className={`${tableStyles.td} max-w-md`}
                                            >
                                                <span
                                                    className="block truncate font-mono text-xs"
                                                    title={process.command}
                                                >
                                                    {process.command}
                                                </span>
                                            </td>
                                            <td
                                                className={`${tableStyles.td} text-right`}
                                            >
                                                {process.pid > 1 && (
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        aria-label={`Hentikan proses ${process.pid}`}
                                                        onClick={() => {
                                                            setError(null);
                                                            setPending({
                                                                process,
                                                                signal: 'TERM',
                                                            });
                                                        }}
                                                    >
                                                        <Square />
                                                    </Button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        {processes.length === 0 && (
                            <p className="px-6 py-6 text-sm text-muted-foreground">
                                Tidak ada proses yang cocok.
                            </p>
                        )}
                    </Panel>
                </>
            )}

            <Dialog
                open={pending !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setPending(null);
                    }
                }}
            >
                <DialogContent className="rounded-2xl">
                    {pending && (
                        <>
                            <DialogHeader>
                                <DialogTitle className="flex items-center gap-2">
                                    <Cpu className="size-5" /> Hentikan proses{' '}
                                    {pending.process.pid}?
                                </DialogTitle>
                                <DialogDescription className="break-all">
                                    {pending.process.user}:{' '}
                                    <span className="font-mono">
                                        {pending.process.command}
                                    </span>
                                </DialogDescription>
                            </DialogHeader>
                            <div className="grid gap-2">
                                {signals.map((signal) => (
                                    <label
                                        key={signal}
                                        className="flex cursor-pointer items-start gap-3 rounded-xl border p-3 has-checked:border-primary"
                                    >
                                        <input
                                            type="radio"
                                            name="signal"
                                            className="mt-1"
                                            checked={pending.signal === signal}
                                            onChange={() =>
                                                setPending({
                                                    ...pending,
                                                    signal,
                                                })
                                            }
                                        />
                                        <span className="grid gap-0.5">
                                            <span className="font-semibold">
                                                {signalLabels[signal].title}
                                            </span>
                                            <span className="text-sm text-muted-foreground">
                                                {
                                                    signalLabels[signal]
                                                        .description
                                                }
                                            </span>
                                        </span>
                                    </label>
                                ))}
                            </div>
                            <p className="text-sm text-muted-foreground">
                                Menghentikan layanan server (Nginx, MariaDB,
                                PHP-FPM, dan lainnya) membuat website terputus
                                sampai layanan itu jalan lagi.
                            </p>
                            {error && (
                                <Alert variant="destructive">
                                    <AlertDescription>{error}</AlertDescription>
                                </Alert>
                            )}
                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button type="button" variant="secondary">
                                        Batal
                                    </Button>
                                </DialogClose>
                                <Button
                                    variant="destructive"
                                    disabled={processing}
                                    onClick={send}
                                >
                                    {processing && <Spinner />}
                                    {signalLabels[pending.signal].title}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}
