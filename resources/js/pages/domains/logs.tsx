import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    ChartColumn,
    Download,
    ExternalLink,
    RefreshCw,
    Search,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { PageHeader } from '@/components/page-header';
import { Panel } from '@/components/panel';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatBytes, formatDateTime } from '@/lib/format';
import { index as domainsIndex, statistics } from '@/routes/domains';
import { download, show } from '@/routes/domains/logs';

type Kind = 'access' | 'error' | 'apache-error';

type Log = {
    lines: string[];
    size: number;
    modified_at: string | null;
    partial: boolean;
    error: string | null;
};

type Props = {
    domain: { id: number; name: string; display_name: string; status: string };
    kind: Kind;
    lines: number;
    filter: string;
    log: Log;
    options: { kinds: Kind[]; line_choices: number[]; max_filter: number };
};

const kindLabels: Record<Kind, { title: string; description: string }> = {
    access: {
        title: 'Akses',
        description:
            'Setiap kunjungan: IP, waktu, halaman, status, dan browser.',
    },
    error: {
        title: 'Error',
        description:
            'Error dari Nginx: permintaan yang ditolak, gangguan ke Apache, dan masalah koneksi. Halaman yang tidak ditemukan tercatat di log akses dengan status 404.',
    },
    'apache-error': {
        title: 'Error PHP',
        description:
            'Error dari Apache dan PHP: pesan error, peringatan, dan skrip yang gagal.',
    },
};

export default function DomainLogs({
    domain,
    kind,
    lines,
    filter,
    log,
    options,
}: Props) {
    const [search, setSearch] = useState(filter);
    const [loading, setLoading] = useState(false);

    const visit = (next: { kind?: Kind; lines?: number; filter?: string }) => {
        const query = { kind, lines, filter: search, ...next };

        router.get(
            show.url(domain.id),
            {
                kind: query.kind,
                lines: query.lines,
                ...(query.filter !== '' ? { filter: query.filter } : {}),
            },
            {
                only: ['kind', 'lines', 'filter', 'log'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onStart: () => setLoading(true),
                onFinish: () => setLoading(false),
            },
        );
    };

    const submitFilter = (event: FormEvent) => {
        event.preventDefault();
        visit({ filter: search.trim() });
    };

    return (
        <>
            <Head title={`Log ${domain.display_name}`} />

            <div>
                <Button variant="ghost" size="sm" asChild className="-ml-2">
                    <Link href={domainsIndex()}>
                        <ArrowLeft /> Domains
                    </Link>
                </Button>
            </div>

            <PageHeader
                title="Log & statistik"
                description={
                    <>
                        Log website{' '}
                        <span className="font-semibold text-foreground">
                            {domain.display_name}
                        </span>{' '}
                        dari 14 hari terakhir, dan laporan pengunjungnya.
                    </>
                }
            />

            <Panel
                title="Statistik pengunjung"
                description="Laporan GoAccess dari log akses 14 hari terakhir: pengunjung, halaman, sumber kunjungan, negara, browser, dan error. Dibuat saat dibuka, jadi bisa butuh beberapa detik untuk website yang ramai."
            >
                <div>
                    <Button asChild>
                        <a
                            href={statistics.url(domain.id)}
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            <ChartColumn /> Buka laporan{' '}
                            <ExternalLink className="size-3.5" />
                        </a>
                    </Button>
                </div>
            </Panel>

            <Panel
                title="Log"
                description={kindLabels[kind].description}
                action={
                    <Button variant="outline" size="sm" asChild>
                        <a href={download.url(domain.id, { query: { kind } })}>
                            <Download /> Unduh 14 hari
                        </a>
                    </Button>
                }
            >
                <div className="grid gap-4">
                    <div className="flex flex-wrap items-center gap-2">
                        {options.kinds.map((option) => (
                            <Button
                                key={option}
                                size="sm"
                                variant={
                                    option === kind ? 'default' : 'outline'
                                }
                                onClick={() => visit({ kind: option })}
                            >
                                {kindLabels[option].title}
                            </Button>
                        ))}
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <form
                            onSubmit={submitFilter}
                            className="flex min-w-64 flex-1 items-center gap-2"
                        >
                            <Input
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                                maxLength={options.max_filter}
                                placeholder="Saring, misalnya IP, /wp-login.php, atau 404"
                                aria-label="Saring baris"
                                className="font-mono"
                            />
                            <Button
                                type="submit"
                                variant="outline"
                                size="icon"
                                aria-label="Saring"
                            >
                                <Search />
                            </Button>
                        </form>
                        <Select
                            value={String(lines)}
                            onValueChange={(value) =>
                                visit({ lines: Number(value) })
                            }
                        >
                            <SelectTrigger
                                className="w-40"
                                aria-label="Jumlah baris"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {options.line_choices.map((choice) => (
                                    <SelectItem
                                        key={choice}
                                        value={String(choice)}
                                    >
                                        {choice} baris terakhir
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Button
                            variant="outline"
                            size="icon"
                            aria-label="Muat ulang"
                            disabled={loading}
                            onClick={() => visit({})}
                        >
                            <RefreshCw
                                className={loading ? 'animate-spin' : undefined}
                            />
                        </Button>
                    </div>

                    {log.error !== null ? (
                        <p className="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
                            {log.error}
                        </p>
                    ) : log.lines.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {filter !== ''
                                ? `Tidak ada baris yang berisi “${filter}”.`
                                : 'Log ini masih kosong.'}
                        </p>
                    ) : (
                        <pre className="max-h-[32rem] overflow-auto rounded-xl border bg-muted/40 p-4 font-mono text-xs leading-relaxed whitespace-pre">
                            {log.lines.join('\n')}
                        </pre>
                    )}

                    {log.modified_at && (
                        <p className="text-xs text-muted-foreground">
                            Log hari ini {formatBytes(log.size)}, terakhir
                            ditulis {formatDateTime(log.modified_at)}.
                            {log.partial &&
                                ' Hanya 4 MB terakhir yang dicari; unduh lognya untuk melihat semuanya.'}
                        </p>
                    )}
                </div>
            </Panel>
        </>
    );
}
