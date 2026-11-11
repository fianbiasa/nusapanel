import { Head, router } from '@inertiajs/react';
import { Inbox, RefreshCw, RotateCw, ShieldAlert, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/components/page-header';
import { Panel, tableStyles } from '@/components/panel';
import { StatusBadge } from '@/components/status-badge';
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
import { formatBytes, formatDateTime, formatRelative } from '@/lib/format';
import { destroy, retry } from '@/routes/admin/mail-queue';

type QueuedMessage = {
    queue: string;
    id: string;
    arrived_at: number;
    size: number;
    sender: string;
    recipients: { address: string; reason?: string }[];
};

type Props = {
    queue: { total: number; messages: QueuedMessage[] } | null;
    error: string | null;
};

/**
 * Where a message waits, as a badge: the queues of Postfix in words.
 */
const queues: Record<string, { status: string; label: string }> = {
    deferred: { status: 'suspended', label: 'Tertunda' },
    active: { status: 'running', label: 'Sedang dikirim' },
    incoming: { status: 'queued', label: 'Masuk antrean' },
    maildrop: { status: 'queued', label: 'Menunggu' },
    hold: { status: 'cancelled', label: 'Ditahan' },
};

export default function MailQueue({ queue, error }: Props) {
    const [selected, setSelected] = useState<string[]>([]);
    const [deleting, setDeleting] = useState<string[] | null>(null);
    const [busy, setBusy] = useState(false);
    const [actionError, setActionError] = useState<string | null>(null);

    const messages = queue?.messages ?? [];
    const allSelected =
        messages.length > 0 && selected.length === messages.length;

    function retryMessages(id?: string) {
        setActionError(null);
        router.post(retry.url(), id ? { id } : {}, {
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onError: (errors) =>
                setActionError(Object.values(errors)[0] ?? null),
        });
    }

    function deleteMessages(ids: string[]) {
        setActionError(null);
        router.delete(destroy.url(), {
            data: { ids },
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onSuccess: () => {
                setDeleting(null);
                setSelected((current) =>
                    current.filter((id) => !ids.includes(id)),
                );
            },
            onError: (errors) => {
                setDeleting(null);
                setActionError(Object.values(errors)[0] ?? null);
            },
        });
    }

    return (
        <>
            <Head title="Antrean Email" />

            <PageHeader
                title="Antrean Email"
                description="Email dari server ini yang belum terkirim. Tertunda: server tujuan belum menerimanya, dan Postfix mencoba lagi otomatis sampai 5 hari. Menunggu: belum diproses, termasuk yang ditahan batas kirim harian; email itu berangkat sendiri begitu batasnya longgar lagi."
                actions={
                    <div className="flex flex-wrap gap-2">
                        <Button
                            variant="secondary"
                            disabled={busy}
                            onClick={() =>
                                router.reload({ only: ['queue', 'error'] })
                            }
                        >
                            <RefreshCw />
                            Muat ulang
                        </Button>
                        <Button
                            disabled={busy || messages.length === 0}
                            onClick={() => retryMessages()}
                        >
                            {busy ? <Spinner /> : <RotateCw />}
                            Kirim ulang semua
                        </Button>
                    </div>
                }
            />

            {error !== null && (
                <Alert variant="destructive">
                    <ShieldAlert />
                    <AlertDescription>{error}</AlertDescription>
                </Alert>
            )}

            {actionError !== null && (
                <Alert variant="destructive">
                    <ShieldAlert />
                    <AlertDescription>{actionError}</AlertDescription>
                </Alert>
            )}

            {queue !== null && messages.length === 0 && (
                <Panel title="Antrean kosong">
                    <p className="flex items-center gap-2 text-sm text-muted-foreground">
                        <Inbox className="size-4" />
                        Semua email dari server ini sudah terkirim.
                    </p>
                </Panel>
            )}

            {messages.length > 0 && (
                <Panel
                    title={`${queue?.total ?? 0} email di antrean`}
                    description={
                        (queue?.total ?? 0) > messages.length
                            ? `Menampilkan ${messages.length} yang terbaru.`
                            : undefined
                    }
                    flush
                >
                    {selected.length > 0 && (
                        <div className="flex items-center justify-between gap-3 border-t bg-muted/30 px-6 py-3 text-sm">
                            <span>{selected.length} dipilih</span>
                            <Button
                                variant="destructive"
                                size="sm"
                                disabled={busy}
                                onClick={() => setDeleting(selected)}
                            >
                                <Trash2 />
                                Hapus yang dipilih
                            </Button>
                        </div>
                    )}
                    <div className="overflow-x-auto">
                        <table className={tableStyles.table}>
                            <thead className={tableStyles.head}>
                                <tr>
                                    <th className={`${tableStyles.th} w-10`}>
                                        <Checkbox
                                            aria-label="Pilih semua"
                                            checked={allSelected}
                                            onCheckedChange={(checked) =>
                                                setSelected(
                                                    checked === true
                                                        ? messages.map(
                                                              (message) =>
                                                                  message.id,
                                                          )
                                                        : [],
                                                )
                                            }
                                        />
                                    </th>
                                    <th className={tableStyles.th}>Masuk</th>
                                    <th className={tableStyles.th}>
                                        Pengirim dan penerima
                                    </th>
                                    <th className={tableStyles.th}>Status</th>
                                    <th
                                        className={`${tableStyles.th} hidden text-right sm:table-cell`}
                                    >
                                        Ukuran
                                    </th>
                                    <th className={tableStyles.th}>
                                        <span className="sr-only">Aksi</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {messages.map((message) => (
                                    <MessageRow
                                        key={message.id}
                                        message={message}
                                        selected={selected.includes(message.id)}
                                        busy={busy}
                                        onSelect={(checked) =>
                                            setSelected((current) =>
                                                checked
                                                    ? [...current, message.id]
                                                    : current.filter(
                                                          (id) =>
                                                              id !== message.id,
                                                      ),
                                            )
                                        }
                                        onRetry={() =>
                                            retryMessages(message.id)
                                        }
                                        onDelete={() =>
                                            setDeleting([message.id])
                                        }
                                    />
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Panel>
            )}

            <Dialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            Hapus {deleting?.length ?? 0} email dari antrean?
                        </DialogTitle>
                        <DialogDescription>
                            Email yang dihapus tidak akan dikirim dan tidak bisa
                            dikembalikan. Pengirimnya tidak diberi tahu.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button variant="secondary">Batal</Button>
                        </DialogClose>
                        <Button
                            variant="destructive"
                            disabled={busy}
                            onClick={() =>
                                deleting !== null && deleteMessages(deleting)
                            }
                        >
                            {busy ? <Spinner /> : <Trash2 />}
                            Hapus
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function MessageRow({
    message,
    selected,
    busy,
    onSelect,
    onRetry,
    onDelete,
}: {
    message: QueuedMessage;
    selected: boolean;
    busy: boolean;
    onSelect: (checked: boolean) => void;
    onRetry: () => void;
    onDelete: () => void;
}) {
    const arrived = new Date(message.arrived_at * 1000).toISOString();
    const badge = queues[message.queue] ?? {
        status: 'queued',
        label: message.queue,
    };

    return (
        <tr className={tableStyles.row}>
            <td className={`${tableStyles.td} w-10 align-top`}>
                <Checkbox
                    aria-label={`Pilih ${message.id}`}
                    checked={selected}
                    onCheckedChange={(checked) => onSelect(checked === true)}
                />
            </td>
            <td
                className={`${tableStyles.td} align-top whitespace-nowrap`}
                title={formatDateTime(arrived)}
            >
                {formatRelative(arrived)}
                <div className="font-mono text-xs text-muted-foreground">
                    {message.id}
                </div>
            </td>
            <td className={`${tableStyles.td} align-top`}>
                <div className="font-medium break-all">
                    {message.sender === ''
                        ? '(tanpa pengirim)'
                        : message.sender}
                </div>
                <ul className="mt-1 grid gap-1 text-xs text-muted-foreground">
                    {message.recipients.map((recipient) => (
                        <li key={recipient.address} className="break-all">
                            → {recipient.address}
                            {recipient.reason && (
                                <span className="block text-warning-foreground">
                                    {recipient.reason}
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
            </td>
            <td className={`${tableStyles.td} align-top`}>
                <StatusBadge status={badge.status} label={badge.label} />
            </td>
            <td
                className={`${tableStyles.td} hidden text-right align-top tabular-nums sm:table-cell`}
            >
                {formatBytes(message.size)}
            </td>
            <td className={`${tableStyles.td} align-top`}>
                <div className="flex justify-end gap-1">
                    {message.queue === 'deferred' && (
                        <Button
                            variant="ghost"
                            size="sm"
                            disabled={busy}
                            onClick={onRetry}
                        >
                            <RotateCw />
                            Kirim ulang
                        </Button>
                    )}
                    <Button
                        variant="ghost"
                        size="sm"
                        disabled={busy}
                        onClick={onDelete}
                        aria-label={`Hapus ${message.id}`}
                    >
                        <Trash2 />
                    </Button>
                </div>
            </td>
        </tr>
    );
}
