import { Head, router, usePoll } from '@inertiajs/react';
import {
    Archive,
    Download,
    History,
    LoaderCircle,
    RotateCcw,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import {
    BackupChoice,
    BackupRestoreDialog,
} from '@/components/backup-restore-dialog';
import type { InspectedBackup } from '@/components/backup-restore-dialog';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Panel, tableStyles } from '@/components/panel';
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
import { formatBytes, formatDateTime } from '@/lib/format';
import { destroy, restore, store } from '@/routes/backups';
import { download } from '@/routes/files';

type Backup = { name: string; size: number; modified: number };

type Props = {
    backups: Backup[];
    error: string | null;
    busy: boolean;
    counts: { databases: number; domains: number };
    inspected?: InspectedBackup | null;
};

export default function Backups({
    backups,
    error,
    busy,
    counts,
    inspected,
}: Props) {
    const [items, setItems] = useState({
        files: true,
        databases: true,
        email: true,
    });
    const [creating, setCreating] = useState(false);
    const [createError, setCreateError] = useState<string | undefined>();
    const [restoring, setRestoring] = useState<string | null>(null);
    const [deleting, setDeleting] = useState<Backup | null>(null);

    usePoll(5000, { only: ['backups', 'busy'] }, { autoStart: busy });

    const create = () => {
        setCreating(true);
        setCreateError(undefined);
        router.post(store.url(), items, {
            preserveScroll: true,
            onError: (errors) => setCreateError(errors.items ?? errors.backup),
            onFinish: () => setCreating(false),
        });
    };

    const openRestore = (name: string) => {
        setRestoring(name);
        router.reload({ only: ['inspected'], data: { restore: name } });
    };

    return (
        <>
            <Head title="Backup" />

            <PageHeader
                title="Backup"
                description="Salinan website, database, dan email akun kamu, disimpan di folder backups di home kamu."
            />

            {busy && (
                <div className="flex items-center gap-3 rounded-2xl border border-primary/20 bg-primary/5 px-5 py-4 text-sm">
                    <LoaderCircle className="size-4 animate-spin text-primary" />
                    Backup atau restore sedang berjalan. Halaman ini diperbarui
                    sendiri; detailnya ada di menu Tasks.
                </div>
            )}

            <Panel
                title="Buat backup"
                description="Setiap backup adalah salinan penuh dan ikut memakai kuota disk akunmu. Hapus backup lama yang tidak diperlukan lagi supaya disk tidak penuh."
            >
                <div className="grid gap-4">
                    <BackupChoice
                        id="backup-files"
                        checked={items.files}
                        onChange={(files) => setItems({ ...items, files })}
                        label="File website dan home"
                        hint={`Folder domains (${counts.domains} website) dan file lain di home, tanpa folder backups, trash, dan tmp.`}
                    />
                    <BackupChoice
                        id="backup-databases"
                        checked={items.databases}
                        onChange={(databases) =>
                            setItems({ ...items, databases })
                        }
                        label="Database"
                        hint={`${counts.databases} database MariaDB beserta user-nya.`}
                    />
                    <BackupChoice
                        id="backup-email"
                        checked={items.email}
                        onChange={(email) => setItems({ ...items, email })}
                        label="Email"
                        hint="Isi semua akun email beserta akunnya."
                    />
                    <InputError message={createError} />
                    <div>
                        <Button onClick={create} disabled={creating || busy}>
                            {creating ? <Spinner /> : <Archive />}
                            Buat backup
                        </Button>
                    </div>
                </div>
            </Panel>

            <Panel
                flush
                title="Daftar backup"
                description="Unduh untuk menyimpannya di luar server. Backup yang diunggah ke folder backups lewat File Manager atau FTP juga bisa dipulihkan."
            >
                {error && (
                    <p className="px-6 pb-4 text-sm text-destructive">
                        {error}
                    </p>
                )}
                {backups.length === 0 ? (
                    <div className="flex flex-col items-center gap-2 px-6 pt-4 pb-10 text-center text-sm text-muted-foreground">
                        <History className="size-8" />
                        Belum ada backup.
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className={tableStyles.table}>
                            <thead className={tableStyles.head}>
                                <tr>
                                    <th className={tableStyles.th}>File</th>
                                    <th className={tableStyles.th}>Ukuran</th>
                                    <th className={tableStyles.th}>Dibuat</th>
                                    <th className={tableStyles.th}>
                                        <span className="sr-only">Aksi</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {backups.map((backup) => (
                                    <tr
                                        key={backup.name}
                                        className={tableStyles.row}
                                    >
                                        <td
                                            className={`${tableStyles.td} font-mono text-xs`}
                                        >
                                            {backup.name}
                                        </td>
                                        <td className={tableStyles.td}>
                                            {formatBytes(backup.size)}
                                        </td>
                                        <td
                                            className={`${tableStyles.td} whitespace-nowrap`}
                                        >
                                            {formatDateTime(
                                                new Date(
                                                    backup.modified * 1000,
                                                ).toISOString(),
                                            )}
                                        </td>
                                        <td className={tableStyles.td}>
                                            <div className="flex justify-end gap-1">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    asChild
                                                >
                                                    <a
                                                        href={
                                                            download({
                                                                query: {
                                                                    path: `backups/${backup.name}`,
                                                                },
                                                            }).url
                                                        }
                                                    >
                                                        <Download /> Unduh
                                                    </a>
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    disabled={busy}
                                                    onClick={() =>
                                                        openRestore(backup.name)
                                                    }
                                                >
                                                    <RotateCcw /> Pulihkan
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    disabled={busy}
                                                    aria-label={`Hapus ${backup.name}`}
                                                    onClick={() =>
                                                        setDeleting(backup)
                                                    }
                                                >
                                                    <Trash2 />
                                                </Button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Panel>

            <BackupRestoreDialog
                name={restoring}
                inspected={
                    inspected && inspected.name === restoring ? inspected : null
                }
                action={restoring ? restore.url(restoring) : null}
                onClose={() => setRestoring(null)}
            />

            <Dialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
            >
                <DialogContent className="rounded-2xl">
                    <DialogHeader>
                        <DialogTitle>Hapus backup?</DialogTitle>
                        <DialogDescription>
                            {deleting?.name} dihapus permanen, tidak masuk
                            trash.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button variant="outline">Batal</Button>
                        </DialogClose>
                        <Button
                            variant="destructive"
                            onClick={() =>
                                deleting &&
                                router.delete(destroy.url(deleting.name), {
                                    preserveScroll: true,
                                    onFinish: () => setDeleting(null),
                                })
                            }
                        >
                            Hapus
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
