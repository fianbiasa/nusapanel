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
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Panel, tableStyles } from '@/components/panel';
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
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { formatBytes, formatDateTime } from '@/lib/format';
import { destroy, restore, store } from '@/routes/backups';
import { download } from '@/routes/files';

type Backup = { name: string; size: number; modified: number };

type Inspected =
    | {
          name: string;
          created_at: string | null;
          files: boolean;
          email: boolean;
          databases: string[];
          mailboxes: string[];
          error?: undefined;
      }
    | { name: string; error: string };

type Props = {
    backups: Backup[];
    error: string | null;
    busy: boolean;
    counts: { databases: number; domains: number };
    inspected?: Inspected | null;
};

/** One labelled checkbox. */
function Choice({
    id,
    checked,
    onChange,
    label,
    hint,
    disabled = false,
}: {
    id: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
    label: string;
    hint?: string;
    disabled?: boolean;
}) {
    return (
        <div className="flex items-start gap-3">
            <Checkbox
                id={id}
                checked={checked}
                disabled={disabled}
                onCheckedChange={(value) => onChange(value === true)}
            />
            <div className="grid gap-0.5">
                <Label htmlFor={id} className="font-medium">
                    {label}
                </Label>
                {hint && (
                    <p className="text-xs text-muted-foreground">{hint}</p>
                )}
            </div>
        </div>
    );
}

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
                    <Choice
                        id="backup-files"
                        checked={items.files}
                        onChange={(files) => setItems({ ...items, files })}
                        label="File website dan home"
                        hint={`Folder domains (${counts.domains} website) dan file lain di home, tanpa folder backups, trash, dan tmp.`}
                    />
                    <Choice
                        id="backup-databases"
                        checked={items.databases}
                        onChange={(databases) =>
                            setItems({ ...items, databases })
                        }
                        label="Database"
                        hint={`${counts.databases} database MariaDB beserta user-nya.`}
                    />
                    <Choice
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

            <RestoreDialog
                name={restoring}
                inspected={
                    inspected && inspected.name === restoring ? inspected : null
                }
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

/** Choose what of a backup goes back into the account. */
function RestoreDialog({
    name,
    inspected,
    onClose,
}: {
    name: string | null;
    inspected: Inspected | null;
    onClose: () => void;
}) {
    const [choice, setChoice] = useState<{
        for: string | null;
        files: boolean;
        email: boolean;
        databases: string[];
    }>({ for: null, files: false, email: false, databases: [] });
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const backup = inspected && 'files' in inspected ? inspected : null;

    // A newly read backup starts with all it holds chosen.
    if (backup && choice.for !== backup.name) {
        setChoice({
            for: backup.name,
            files: backup.files,
            email: backup.email,
            databases: backup.databases,
        });
    }

    const submit = () => {
        if (!backup) {
            return;
        }

        setProcessing(true);
        router.post(
            restore.url(backup.name),
            {
                files: choice.files,
                email: choice.email,
                databases: choice.databases,
            },
            {
                preserveScroll: true,
                onSuccess: () => onClose(),
                onError: (failed) => setErrors(failed),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Dialog
            open={name !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent className="rounded-2xl sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Pulihkan backup</DialogTitle>
                    <DialogDescription className="font-mono text-xs">
                        {name}
                    </DialogDescription>
                </DialogHeader>

                {inspected === null ? (
                    <div className="grid gap-3">
                        <Skeleton className="h-5 w-2/3" />
                        <Skeleton className="h-5 w-1/2" />
                        <Skeleton className="h-5 w-3/5" />
                    </div>
                ) : !backup ? (
                    <p className="text-sm text-destructive">
                        {inspected.error}
                    </p>
                ) : (
                    backup && (
                        <div className="grid gap-4">
                            {backup.created_at && (
                                <p className="text-sm text-muted-foreground">
                                    Dibuat {formatDateTime(backup.created_at)}.
                                </p>
                            )}
                            {backup.files && (
                                <Choice
                                    id="restore-files"
                                    checked={choice.files}
                                    onChange={(files) =>
                                        setChoice({ ...choice, files })
                                    }
                                    label="File website dan home"
                                    hint="File di backup menggantikan versinya yang sekarang; file yang dibuat setelah backup tetap ada."
                                />
                            )}
                            {backup.email && (
                                <Choice
                                    id="restore-email"
                                    checked={choice.email}
                                    onChange={(email) =>
                                        setChoice({ ...choice, email })
                                    }
                                    label="Email"
                                    hint={`${backup.mailboxes.length} akun email. Akun email yang sudah dihapus dibuat lagi dengan password lamanya; email yang ada tetap ada.`}
                                />
                            )}
                            {backup.databases.map((database) => (
                                <Choice
                                    key={database}
                                    id={`restore-${database}`}
                                    checked={choice.databases.includes(
                                        database,
                                    )}
                                    onChange={(checked) =>
                                        setChoice({
                                            ...choice,
                                            databases: checked
                                                ? [
                                                      ...choice.databases,
                                                      database,
                                                  ]
                                                : choice.databases.filter(
                                                      (name) =>
                                                          name !== database,
                                                  ),
                                        })
                                    }
                                    label={`Database ${database}`}
                                    hint="Isinya diganti isi backup. Database dan user-nya yang sudah dihapus dibuat lagi."
                                />
                            ))}
                            <InputError
                                message={
                                    errors.backup ??
                                    errors.items ??
                                    errors.databases ??
                                    errors.name
                                }
                            />
                        </div>
                    )
                )}

                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button variant="outline">Batal</Button>
                    </DialogClose>
                    <Button onClick={submit} disabled={!backup || processing}>
                        {processing ? <Spinner /> : <RotateCcw />}
                        Pulihkan
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
