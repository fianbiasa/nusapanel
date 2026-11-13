import { Head } from '@inertiajs/react';
import { DailyBars } from '@/components/daily-bars';
import { PageHeader } from '@/components/page-header';
import { Panel, tableStyles } from '@/components/panel';
import { formatBytes, formatCpuTime, formatMegabytes } from '@/lib/format';
import type { AccountScope } from '@/lib/scoped-routes';

type HistoryPoint = {
    date: string;
    bandwidth_bytes: number;
    disk_kb: number | null;
    cpu_ms: number;
    memory_peak_mb: number | null;
};

type Props = {
    scope: AccountScope;
    totals: { bandwidth_bytes: number; disk_kb: number; accounts: number };
    history: HistoryPoint[];
    topBandwidth: {
        id: number;
        username: string;
        bytes: number;
        limit_mb: number | null;
    }[];
    topDisk: {
        id: number;
        username: string;
        disk_kb: number;
        limit_mb: number | null;
    }[];
};

const formatKilobytes = (kilobytes: number) => formatBytes(kilobytes * 1024);

export default function Statistics({
    scope,
    totals,
    history,
    topBandwidth,
    topDisk,
}: Props) {
    return (
        <>
            <Head title="Statistik" />

            <PageHeader
                title="Statistik"
                description={
                    scope === 'admin'
                        ? 'Pemakaian bandwidth, disk, CPU, dan RAM semua akun hosting di server ini.'
                        : 'Pemakaian bandwidth, disk, CPU, dan RAM akun-akun hosting milik Anda.'
                }
            />

            <section className="grid gap-3 sm:grid-cols-3 sm:gap-5">
                <Stat
                    label="Bandwidth bulan ini"
                    value={formatBytes(totals.bandwidth_bytes)}
                />
                <Stat
                    label="Disk terpakai"
                    value={formatKilobytes(totals.disk_kb)}
                />
                <Stat
                    label="Akun hosting"
                    value={totals.accounts.toLocaleString('id-ID')}
                />
            </section>

            <Panel
                title="30 hari terakhir"
                description="Bandwidth dari log website, email, dan FTP; disk dari pengukuran terakhir tiap hari; CPU dan RAM dari PHP dan sesi SSH, diukur tiap 5 menit. RAM puncak adalah puncak tiap akun, dijumlahkan."
            >
                <div className="grid gap-8 lg:grid-cols-2">
                    <DailyBars
                        title="Bandwidth per hari"
                        points={history.map((point) => ({
                            date: point.date,
                            value: point.bandwidth_bytes,
                        }))}
                        format={formatBytes}
                    />
                    <DailyBars
                        title="Disk terpakai"
                        points={history.map((point) => ({
                            date: point.date,
                            value: point.disk_kb,
                        }))}
                        format={formatKilobytes}
                    />
                    <DailyBars
                        title="CPU per hari"
                        points={history.map((point) => ({
                            date: point.date,
                            value: point.cpu_ms,
                        }))}
                        format={formatCpuTime}
                    />
                    <DailyBars
                        title="RAM puncak"
                        points={history.map((point) => ({
                            date: point.date,
                            value: point.memory_peak_mb,
                        }))}
                        format={formatMegabytes}
                    />
                </div>
            </Panel>

            <div className="grid gap-6 lg:grid-cols-2">
                <Panel flush title="Bandwidth terbesar bulan ini">
                    <Ranking
                        rows={topBandwidth.map((row) => ({
                            id: row.id,
                            username: row.username,
                            value: formatBytes(row.bytes),
                            limit:
                                row.limit_mb === null
                                    ? null
                                    : formatMegabytes(row.limit_mb),
                            over:
                                row.limit_mb !== null &&
                                row.bytes > row.limit_mb * 1048576,
                        }))}
                        empty="Belum ada bandwidth yang tercatat bulan ini."
                    />
                </Panel>
                <Panel flush title="Disk terbesar">
                    <Ranking
                        rows={topDisk.map((row) => ({
                            id: row.id,
                            username: row.username,
                            value: formatKilobytes(row.disk_kb),
                            limit:
                                row.limit_mb === null
                                    ? null
                                    : formatMegabytes(row.limit_mb),
                            over:
                                row.limit_mb !== null &&
                                row.disk_kb > row.limit_mb * 1024,
                        }))}
                        empty="Disk belum pernah diukur."
                    />
                </Panel>
            </div>
        </>
    );
}

function Stat({ label, value }: { label: string; value: string }) {
    return (
        <article className="rounded-2xl border bg-card p-5 shadow-card">
            <div className="text-[26px] leading-none font-extrabold tracking-tight">
                {value}
            </div>
            <div className="mt-2 text-sm text-muted-foreground">{label}</div>
        </article>
    );
}

function Ranking({
    rows,
    empty,
}: {
    rows: {
        id: number;
        username: string;
        value: string;
        limit: string | null;
        over: boolean;
    }[];
    empty: string;
}) {
    if (rows.length === 0) {
        return (
            <p className="px-6 pt-2 pb-6 text-sm text-muted-foreground">
                {empty}
            </p>
        );
    }

    return (
        <div className="overflow-x-auto">
            <table className={tableStyles.table}>
                <thead className={tableStyles.head}>
                    <tr>
                        <th className={`${tableStyles.th} w-10`}>#</th>
                        <th className={tableStyles.th}>Akun</th>
                        <th className={tableStyles.th}>Pemakaian</th>
                        <th className={tableStyles.th}>Batas</th>
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {rows.map((row, index) => (
                        <tr key={row.id} className={tableStyles.row}>
                            <td
                                className={`${tableStyles.td} text-muted-foreground`}
                            >
                                {index + 1}
                            </td>
                            <td className={tableStyles.td}>
                                <code className="font-mono">
                                    {row.username}
                                </code>
                            </td>
                            <td
                                className={`${tableStyles.td} font-semibold whitespace-nowrap`}
                            >
                                {row.value}
                                {row.over && (
                                    <span className="ml-2 text-xs font-medium text-destructive">
                                        lewat batas
                                    </span>
                                )}
                            </td>
                            <td
                                className={`${tableStyles.td} whitespace-nowrap text-muted-foreground`}
                            >
                                {row.limit ?? 'Tanpa batas'}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
