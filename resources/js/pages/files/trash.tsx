import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, RotateCcw, Trash2 } from 'lucide-react';
import { useState } from 'react';
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
import { Spinner } from '@/components/ui/spinner';
import { formatBytes, formatDateTime } from '@/lib/format';
import { index, purge, restore } from '@/routes/files';

type TrashItem = {
    id: string;
    name: string;
    path: string;
    type: 'file' | 'directory' | 'link' | 'other';
    size: number;
    deleted_at: string;
};

type Props = {
    items: TrashItem[];
    error: string | null;
};

type Purge = { ids: string[] } | { all: true };

export default function FilesTrash({ items, error }: Props) {
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const [purging, setPurging] = useState<Purge | null>(null);
    const [processing, setProcessing] = useState(false);
    const ids = [...selected];
    const allSelected =
        items.length > 0 && items.every((item) => selected.has(item.id));

    const toggle = (id: string) =>
        setSelected((current) => {
            const next = new Set(current);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });

    const done = () => {
        setSelected(new Set());
        setPurging(null);
    };

    const restoreSelected = () => {
        setProcessing(true);
        router.post(
            restore().url,
            { ids },
            {
                preserveScroll: true,
                onSuccess: done,
                onFinish: () => setProcessing(false),
            },
        );
    };

    const purgeConfirmed = () => {
        if (!purging) {
            return;
        }

        setProcessing(true);
        router.post(purge().url, purging, {
            preserveScroll: true,
            onSuccess: done,
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <>
            <Head title="Trash" />

            <PageHeader
                title="Trash"
                description="File dan folder yang dihapus dari File Manager. Pulihkan ke tempat asalnya, atau hapus permanen untuk mengosongkan kuota disk."
                actions={
                    <>
                        <Button variant="secondary" asChild>
                            <Link href={index()}>
                                <ArrowLeft />
                                File Manager
                            </Link>
                        </Button>
                        <Button
                            variant="destructive"
                            disabled={items.length === 0}
                            onClick={() => setPurging({ all: true })}
                        >
                            <Trash2 />
                            Kosongkan Trash
                        </Button>
                    </>
                }
            />

            {error !== null && (
                <Alert variant="destructive">
                    <AlertDescription>{error}</AlertDescription>
                </Alert>
            )}

            <Panel title="Isi Trash" description={`${items.length} item`} flush>
                {selected.size > 0 && (
                    <div className="flex flex-wrap items-center gap-2 border-y bg-accent/60 px-6 py-3 text-sm">
                        <span className="mr-2 font-semibold">
                            {selected.size} dipilih
                        </span>
                        <Button
                            size="sm"
                            onClick={restoreSelected}
                            disabled={processing}
                        >
                            {processing ? <Spinner /> : <RotateCcw />}
                            Pulihkan
                        </Button>
                        <Button
                            size="sm"
                            variant="destructive"
                            onClick={() => setPurging({ ids })}
                        >
                            <Trash2 />
                            Hapus permanen
                        </Button>
                    </div>
                )}

                {items.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 px-6 pt-4 pb-10 text-center">
                        <span className="flex size-14 items-center justify-center rounded-full bg-accent text-accent-foreground">
                            <Trash2 className="size-6" />
                        </span>
                        <p className="max-w-sm text-sm text-muted-foreground">
                            Trash kosong.
                        </p>
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className={tableStyles.table}>
                            <thead className={tableStyles.head}>
                                <tr>
                                    <th className="w-10 py-3 pl-6">
                                        <Checkbox
                                            aria-label="Pilih semua"
                                            checked={allSelected}
                                            onCheckedChange={(checked) =>
                                                setSelected(
                                                    checked
                                                        ? new Set(
                                                              items.map(
                                                                  (item) =>
                                                                      item.id,
                                                              ),
                                                          )
                                                        : new Set(),
                                                )
                                            }
                                        />
                                    </th>
                                    <th className={tableStyles.th}>Nama</th>
                                    <th className={tableStyles.th}>
                                        Lokasi asal
                                    </th>
                                    <th
                                        className={`${tableStyles.th} hidden text-right sm:table-cell`}
                                    >
                                        Ukuran
                                    </th>
                                    <th
                                        className={`${tableStyles.th} hidden md:table-cell`}
                                    >
                                        Dihapus
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {items.map((item) => (
                                    <tr
                                        key={item.id}
                                        className={`${tableStyles.row} ${selected.has(item.id) ? 'bg-accent/40' : ''}`}
                                    >
                                        <td className="py-3 pl-6">
                                            <Checkbox
                                                aria-label={`Pilih ${item.name}`}
                                                checked={selected.has(item.id)}
                                                onCheckedChange={() =>
                                                    toggle(item.id)
                                                }
                                            />
                                        </td>
                                        <td className="px-6 py-3 font-medium break-all">
                                            {item.name}
                                            {item.type === 'directory' && '/'}
                                        </td>
                                        <td className="px-6 py-3 font-mono text-xs break-all text-muted-foreground">
                                            {item.path || 'tidak diketahui'}
                                        </td>
                                        <td className="hidden px-6 py-3 text-right whitespace-nowrap text-muted-foreground tabular-nums sm:table-cell">
                                            {item.type === 'file'
                                                ? formatBytes(item.size)
                                                : '—'}
                                        </td>
                                        <td className="hidden px-6 py-3 whitespace-nowrap text-muted-foreground md:table-cell">
                                            {formatDateTime(item.deleted_at)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Panel>

            <Dialog
                open={purging !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setPurging(null);
                    }
                }}
            >
                <DialogContent className="rounded-2xl">
                    <DialogHeader>
                        <DialogTitle>
                            {purging && 'all' in purging
                                ? 'Kosongkan Trash?'
                                : `Hapus permanen ${ids.length} item?`}
                        </DialogTitle>
                        <DialogDescription>
                            Ini tidak bisa dibatalkan: isinya hilang untuk
                            selamanya.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Batal
                            </Button>
                        </DialogClose>
                        <Button
                            variant="destructive"
                            disabled={processing}
                            onClick={purgeConfirmed}
                        >
                            {processing ? <Spinner /> : <Trash2 />}
                            Hapus permanen
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
