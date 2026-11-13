import { Deferred, Head, Link } from '@inertiajs/react';
import {
    ArrowLeft,
    ChevronRight,
    File,
    Folder,
    FolderOpen,
    HardDrive,
    Link2,
} from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { Panel, tableStyles } from '@/components/panel';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { formatBytes, formatDate, formatMegabytes } from '@/lib/format';
import { index as filesIndex, sizes as sizesRoute } from '@/routes/files';
import type { AccountLimits } from '@/types';

type Entry = {
    name: string;
    type: 'file' | 'directory' | 'link' | 'other';
    size: number;
    files: number;
    folders: number;
};

type LargeFile = { path: string; size: number; modified: number };

type Sizes = {
    path: string;
    size: number;
    files: number;
    folders: number;
    truncated: boolean;
    entries: Entry[];
    largest: LargeFile[];
};

type Props = {
    path: string;
    sizes?: { result: Sizes | null; error: string | null };
    limits: AccountLimits;
};

/** The folder a path is in ("" for the home). */
function parentOf(path: string): string {
    const slash = path.lastIndexOf('/');

    return slash === -1 ? '' : path.slice(0, slash);
}

function join(folder: string, name: string): string {
    return folder === '' ? name : `${folder}/${name}`;
}

function sizesOf(path: string) {
    return path === '' ? sizesRoute() : sizesRoute({ query: { path } });
}

function openIn(path: string) {
    return path === '' ? filesIndex() : filesIndex({ query: { path } });
}

export default function FileSizes({ path, sizes, limits }: Props) {
    const parts = path === '' ? [] : path.split('/');
    const quota = limits.quotas.disk_mb.limit;

    return (
        <>
            <Head title="Pemakaian disk" />

            <div>
                <Button variant="ghost" size="sm" asChild className="-ml-2">
                    <Link href={openIn(path)}>
                        <ArrowLeft /> File Manager
                    </Link>
                </Button>
            </div>

            <PageHeader
                title="Pemakaian disk"
                description="Apa yang paling banyak memakai ruang di home kamu. Klik folder untuk melihat isinya."
            />

            <nav
                aria-label="Folder"
                className="flex flex-wrap items-center gap-1 text-sm"
            >
                <Link
                    href={sizesOf('')}
                    className="inline-flex items-center gap-1 font-semibold text-primary hover:underline"
                >
                    <HardDrive className="size-4" /> Home
                </Link>
                {parts.map((part, index) => {
                    const target = parts.slice(0, index + 1).join('/');

                    return (
                        <span
                            key={target}
                            className="inline-flex items-center gap-1"
                        >
                            <ChevronRight className="size-4 text-muted-foreground" />
                            {index === parts.length - 1 ? (
                                <span className="font-semibold">{part}</span>
                            ) : (
                                <Link
                                    href={sizesOf(target)}
                                    className="font-semibold text-primary hover:underline"
                                >
                                    {part}
                                </Link>
                            )}
                        </span>
                    );
                })}
            </nav>

            <Deferred
                data="sizes"
                fallback={
                    <div className="grid gap-3">
                        <Skeleton className="h-24 w-full" />
                        <Skeleton className="h-80 w-full" />
                    </div>
                }
            >
                {sizes?.error ? (
                    <p className="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
                        {sizes.error}
                    </p>
                ) : sizes?.result ? (
                    <SizesView
                        sizes={sizes.result}
                        quotaMb={path === '' ? quota : null}
                    />
                ) : null}
            </Deferred>
        </>
    );
}

function SizesView({
    sizes,
    quotaMb,
}: {
    sizes: Sizes;
    quotaMb: number | null;
}) {
    const largestEntry = Math.max(1, sizes.entries[0]?.size ?? 0);

    return (
        <>
            <Panel
                title={formatBytes(sizes.size)}
                description={
                    `${sizes.files.toLocaleString('id-ID')} file dan ${sizes.folders.toLocaleString('id-ID')} folder` +
                    (quotaMb !== null
                        ? `, dari kuota ${formatMegabytes(quotaMb)}`
                        : '') +
                    '. Termasuk Trash, yang juga terhitung di kuota.'
                }
                flush
            >
                {sizes.truncated && (
                    <p className="mx-6 mb-4 text-sm text-amber-700 dark:text-amber-300">
                        Folder ini berisi lebih dari satu juta file dan folder;
                        hanya sebagian yang dihitung.
                    </p>
                )}
                {sizes.entries.length === 0 ? (
                    <p className="px-6 pb-6 text-sm text-muted-foreground">
                        Folder ini kosong.
                    </p>
                ) : (
                    <table className={tableStyles.table}>
                        <thead className={tableStyles.head}>
                            <tr>
                                <th className={tableStyles.th}>Nama</th>
                                <th className={`${tableStyles.th} w-1/3`}>
                                    Ukuran
                                </th>
                                <th className={`${tableStyles.th} text-right`}>
                                    Isi
                                </th>
                                <th className={tableStyles.th} />
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {sizes.entries.map((entry) => {
                                const target = join(sizes.path, entry.name);
                                const Icon =
                                    entry.type === 'directory'
                                        ? Folder
                                        : entry.type === 'link'
                                          ? Link2
                                          : File;

                                return (
                                    <tr
                                        key={entry.name}
                                        className={tableStyles.row}
                                    >
                                        <td className={tableStyles.td}>
                                            <span className="flex min-w-0 items-center gap-2">
                                                <Icon className="size-4 shrink-0 text-muted-foreground" />
                                                {entry.type === 'directory' ? (
                                                    <Link
                                                        href={sizesOf(target)}
                                                        className="truncate font-semibold text-primary hover:underline"
                                                    >
                                                        {entry.name}
                                                    </Link>
                                                ) : (
                                                    <span className="truncate">
                                                        {entry.name}
                                                    </span>
                                                )}
                                            </span>
                                        </td>
                                        <td className={tableStyles.td}>
                                            <div className="flex items-center gap-3">
                                                <div className="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                                                    <div
                                                        className="h-full rounded-full bg-primary"
                                                        style={{
                                                            width: `${(entry.size / largestEntry) * 100}%`,
                                                        }}
                                                    />
                                                </div>
                                                <span className="w-20 shrink-0 text-right font-mono text-xs">
                                                    {formatBytes(entry.size)}
                                                </span>
                                            </div>
                                        </td>
                                        <td
                                            className={`${tableStyles.td} text-right text-xs whitespace-nowrap text-muted-foreground`}
                                        >
                                            {entry.type === 'directory'
                                                ? `${entry.files.toLocaleString('id-ID')} file`
                                                : ''}
                                        </td>
                                        <td
                                            className={`${tableStyles.td} text-right`}
                                        >
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                asChild
                                            >
                                                <Link
                                                    href={openIn(
                                                        entry.type ===
                                                            'directory'
                                                            ? target
                                                            : sizes.path,
                                                    )}
                                                    aria-label={`Buka ${entry.name} di File Manager`}
                                                >
                                                    <FolderOpen />
                                                </Link>
                                            </Button>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                )}
            </Panel>

            {sizes.largest.length > 0 && (
                <Panel
                    title="File terbesar"
                    description={`${sizes.largest.length} file terbesar di dalam folder ini, termasuk subfoldernya.`}
                    flush
                >
                    <table className={tableStyles.table}>
                        <thead className={tableStyles.head}>
                            <tr>
                                <th className={tableStyles.th}>File</th>
                                <th className={`${tableStyles.th} text-right`}>
                                    Ukuran
                                </th>
                                <th className={`${tableStyles.th} text-right`}>
                                    Diubah
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {sizes.largest.map((file) => (
                                <tr key={file.path} className={tableStyles.row}>
                                    <td className={`${tableStyles.td} max-w-0`}>
                                        <Link
                                            href={openIn(parentOf(file.path))}
                                            className="block truncate font-mono text-xs text-primary hover:underline"
                                            title={file.path}
                                        >
                                            {file.path}
                                        </Link>
                                    </td>
                                    <td
                                        className={`${tableStyles.td} text-right font-mono text-xs whitespace-nowrap`}
                                    >
                                        {formatBytes(file.size)}
                                    </td>
                                    <td
                                        className={`${tableStyles.td} text-right text-xs whitespace-nowrap text-muted-foreground`}
                                    >
                                        {formatDate(
                                            new Date(
                                                file.modified * 1000,
                                            ).toISOString(),
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </Panel>
            )}
        </>
    );
}
