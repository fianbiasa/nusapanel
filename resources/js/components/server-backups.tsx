import { Form, router } from '@inertiajs/react';
import {
    Archive,
    Download,
    RotateCcw,
    Search,
    Trash2,
    UserPlus,
} from 'lucide-react';
import { useState } from 'react';
import { BackupRestoreDialog } from '@/components/backup-restore-dialog';
import type { InspectedBackup } from '@/components/backup-restore-dialog';
import { GeneratedPasswordInput } from '@/components/generated-password-input';
import InputError from '@/components/input-error';
import { Pagination } from '@/components/pagination';
import { Panel, tableStyles } from '@/components/panel';
import { StatusBadge } from '@/components/status-badge';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { formatBytes, formatDateTime } from '@/lib/format';
import { backupRoutes } from '@/lib/scoped-routes';
import type { AccountScope } from '@/lib/scoped-routes';
import type { Paginated } from '@/types';

export type ServerBackup = {
    id: number;
    username: string;
    account_exists: boolean;
    name: string;
    status: 'queued' | 'running' | 'completed' | 'failed';
    files: boolean;
    email: boolean;
    databases: number;
    size: number | null;
    skipped: number;
    error: string | null;
    automatic: boolean;
    can_delete: boolean;
    created_at: string | null;
};

export type AccountRestore = {
    id: number;
    username: string;
    status: string;
    error: string | null;
    skipped: string[];
    created_at: string | null;
};

/** What a backup of a deleted account says about the account. */
type SavedAccount = {
    username: string;
    email: string | null;
    has_password: boolean;
    owner: string;
    package_id: number | null;
    packages: { id: number; name: string }[];
    domains: string[];
};

export type InspectedServerBackup = InspectedBackup & {
    account?: SavedAccount;
};

export type ServerBackupsProps = {
    scope: AccountScope;
    backups: Paginated<ServerBackup>;
    filters: { search: string };
    accounts: string[];
    manual_keep: number;
    restores: AccountRestore[];
    inspected?: InspectedServerBackup | null;
};

/** What a backup holds, in a few words. */
function contents(backup: ServerBackup): string {
    const parts = [
        backup.files && 'file',
        backup.email && 'email',
        backup.databases > 0 && `${backup.databases} database`,
    ].filter(Boolean);

    return parts.length > 0 ? parts.join(', ') : '–';
}

/** Back up one account now, outside the schedule. */
export function BackupNowPanel({
    scope,
    accounts,
    manualKeep,
}: {
    scope: AccountScope;
    accounts: string[];
    manualKeep: number;
}) {
    const routes = backupRoutes(scope);
    const [username, setUsername] = useState('');
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | undefined>();

    const submit = () => {
        setProcessing(true);
        setError(undefined);
        router.post(
            routes.store.url(),
            { username },
            {
                preserveScroll: true,
                onSuccess: () => setUsername(''),
                onError: (errors) => setError(errors.username),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Panel
            title="Backup satu akun sekarang"
            description={`Backup lengkap (file, database, email) di luar jadwal, disimpan di disk server. Tiap akun menyimpan ${manualKeep} backup manual terbaru; yang lebih lama dihapus otomatis saat backup baru selesai.`}
        >
            <div className="grid gap-2 sm:max-w-md">
                <Label htmlFor="backup-now-username">Username akun</Label>
                <div className="flex gap-2">
                    <Input
                        id="backup-now-username"
                        list="backup-now-accounts"
                        value={username}
                        onChange={(event) => setUsername(event.target.value)}
                        placeholder="Ketik username"
                        autoComplete="off"
                        maxLength={32}
                    />
                    <datalist id="backup-now-accounts">
                        {accounts.map((account) => (
                            <option key={account} value={account} />
                        ))}
                    </datalist>
                    <Button
                        onClick={submit}
                        disabled={processing || username.trim() === ''}
                    >
                        {processing ? <Spinner /> : <Archive />}
                        Backup
                    </Button>
                </div>
                <InputError message={error} />
            </div>
        </Panel>
    );
}

/** Accounts made again from a backup in the last week. */
export function AccountRestoresPanel({
    restores,
}: {
    restores: AccountRestore[];
}) {
    if (restores.length === 0) {
        return null;
    }

    return (
        <Panel
            flush
            title="Akun yang dibuat lagi dari backup"
            description="Seminggu terakhir. Akun dibuat lebih dulu, lalu domain, database, email, dan file-nya kembali."
        >
            <div className="overflow-x-auto">
                <table className={tableStyles.table}>
                    <thead className={tableStyles.head}>
                        <tr>
                            <th className={tableStyles.th}>Akun</th>
                            <th className={tableStyles.th}>Mulai</th>
                            <th className={tableStyles.th}>Status</th>
                            <th className={tableStyles.th}>Catatan</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {restores.map((restore) => (
                            <tr key={restore.id} className={tableStyles.row}>
                                <td className={`${tableStyles.td} font-medium`}>
                                    {restore.username}
                                </td>
                                <td
                                    className={`${tableStyles.td} whitespace-nowrap`}
                                >
                                    {restore.created_at
                                        ? formatDateTime(restore.created_at)
                                        : '–'}
                                </td>
                                <td className={tableStyles.td}>
                                    <StatusBadge status={restore.status} />
                                </td>
                                <td
                                    className={`${tableStyles.td} text-xs text-muted-foreground`}
                                >
                                    {restore.error && (
                                        <p className="text-destructive">
                                            {restore.error}
                                        </p>
                                    )}
                                    {restore.skipped.length > 0 && (
                                        <ul className="grid gap-0.5">
                                            {restore.skipped.map((item) => (
                                                <li key={item}>
                                                    Tidak kembali: {item}
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                    {!restore.error &&
                                        restore.skipped.length === 0 &&
                                        '–'}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </Panel>
    );
}

/**
 * The backups the server keeps, with search, download, restore into the
 * account, making a deleted account again, and delete.
 */
export function ServerBackupsPanel({
    scope,
    backups,
    filters,
    inspected,
}: Pick<ServerBackupsProps, 'scope' | 'backups' | 'filters' | 'inspected'>) {
    const routes = backupRoutes(scope);
    const [restoring, setRestoring] = useState<ServerBackup | null>(null);
    const [deleting, setDeleting] = useState<ServerBackup | null>(null);

    const openRestore = (backup: ServerBackup) => {
        setRestoring(backup);
        router.reload({ only: ['inspected'], data: { restore: backup.id } });
    };

    const shown =
        inspected && inspected.name === restoring?.name ? inspected : null;

    return (
        <>
            <Panel
                flush
                title="Backup tersimpan"
                description="Unduh untuk menyimpannya di luar server. Pulihkan mengembalikan isi backup ke akunnya; untuk akun yang sudah dihapus, Pulihkan membuat akunnya lagi."
                action={
                    <Form
                        {...routes.index.form()}
                        options={{ preserveState: true, replace: true }}
                        className="flex w-full max-w-sm items-center gap-2"
                    >
                        <div className="relative flex-1">
                            <Search className="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                type="search"
                                name="search"
                                defaultValue={filters.search}
                                placeholder="Cari username"
                                aria-label="Cari backup"
                                maxLength={100}
                                className="h-10 pl-10"
                            />
                        </div>
                        <Button type="submit" variant="outline">
                            Cari
                        </Button>
                    </Form>
                }
            >
                {backups.data.length === 0 ? (
                    <div className="flex flex-col items-center gap-2 px-6 pt-4 pb-10 text-center text-sm text-muted-foreground">
                        <Archive className="size-8" />
                        {filters.search
                            ? `Tidak ada backup akun yang cocok dengan "${filters.search}".`
                            : 'Belum ada backup.'}
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className={tableStyles.table}>
                            <thead className={tableStyles.head}>
                                <tr>
                                    <th className={tableStyles.th}>Akun</th>
                                    <th className={tableStyles.th}>Dibuat</th>
                                    <th className={tableStyles.th}>Isi</th>
                                    <th className={tableStyles.th}>Ukuran</th>
                                    <th className={tableStyles.th}>Status</th>
                                    <th className={tableStyles.th}>
                                        <span className="sr-only">Aksi</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {backups.data.map((backup) => (
                                    <tr
                                        key={backup.id}
                                        className={tableStyles.row}
                                    >
                                        <td className={tableStyles.td}>
                                            <div className="font-medium">
                                                {backup.username}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {!backup.account_exists
                                                    ? 'Akun sudah dihapus'
                                                    : backup.automatic
                                                      ? 'Otomatis'
                                                      : 'Manual'}
                                            </div>
                                        </td>
                                        <td
                                            className={`${tableStyles.td} whitespace-nowrap`}
                                        >
                                            {backup.created_at
                                                ? formatDateTime(
                                                      backup.created_at,
                                                  )
                                                : '–'}
                                        </td>
                                        <td className={tableStyles.td}>
                                            {contents(backup)}
                                        </td>
                                        <td
                                            className={`${tableStyles.td} whitespace-nowrap`}
                                        >
                                            {backup.size !== null
                                                ? formatBytes(backup.size)
                                                : '–'}
                                        </td>
                                        <td className={tableStyles.td}>
                                            <StatusBadge
                                                status={backup.status}
                                            />
                                            {backup.error && (
                                                <p className="mt-1 max-w-xs text-xs text-destructive">
                                                    {backup.error}
                                                </p>
                                            )}
                                            {backup.skipped > 0 && (
                                                <p className="mt-1 max-w-xs text-xs text-muted-foreground">
                                                    {backup.skipped} file tidak
                                                    terbaca dan dilewati.
                                                </p>
                                            )}
                                        </td>
                                        <td className={tableStyles.td}>
                                            <div className="flex justify-end gap-1">
                                                {backup.status ===
                                                    'completed' && (
                                                    <>
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            asChild
                                                        >
                                                            <a
                                                                href={
                                                                    routes.download(
                                                                        backup.id,
                                                                    ).url
                                                                }
                                                            >
                                                                <Download />{' '}
                                                                Unduh
                                                            </a>
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() =>
                                                                openRestore(
                                                                    backup,
                                                                )
                                                            }
                                                        >
                                                            {backup.account_exists ? (
                                                                <RotateCcw />
                                                            ) : (
                                                                <UserPlus />
                                                            )}{' '}
                                                            Pulihkan
                                                        </Button>
                                                    </>
                                                )}
                                                {backup.can_delete &&
                                                    backup.status !==
                                                        'running' && (
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            aria-label={`Hapus ${backup.name}`}
                                                            onClick={() =>
                                                                setDeleting(
                                                                    backup,
                                                                )
                                                            }
                                                        >
                                                            <Trash2 />
                                                        </Button>
                                                    )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <div className="px-6 py-4">
                    <Pagination page={backups} />
                </div>
            </Panel>

            {restoring?.account_exists !== false ? (
                <BackupRestoreDialog
                    name={restoring?.name ?? null}
                    inspected={shown}
                    action={restoring ? routes.restore.url(restoring.id) : null}
                    onClose={() => setRestoring(null)}
                >
                    <p className="rounded-xl border bg-muted/40 px-4 py-3 text-sm">
                        Dipulihkan ke akun{' '}
                        <strong>{restoring?.username}</strong>. Restore berjalan
                        di worker backup; kalau ada backup yang sedang berjalan,
                        restore menunggu giliran.
                    </p>
                </BackupRestoreDialog>
            ) : (
                <RestoreAccountDialog
                    backup={restoring}
                    inspected={shown}
                    action={routes.restoreAccount.url(restoring.id)}
                    onClose={() => setRestoring(null)}
                />
            )}

            <Dialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
            >
                <DialogContent className="rounded-2xl">
                    <DialogHeader>
                        <DialogTitle>Hapus backup?</DialogTitle>
                        <DialogDescription>
                            {deleting?.name} dihapus permanen dari disk server.
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
                                router.delete(routes.destroy.url(deleting.id), {
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

/** Make the deleted account of a backup again. */
function RestoreAccountDialog({
    backup,
    inspected,
    action,
    onClose,
}: {
    backup: ServerBackup;
    inspected: InspectedServerBackup | null;
    action: string;
    onClose: () => void;
}) {
    const account = inspected?.account ?? null;
    const [form, setForm] = useState<{
        for: string | null;
        email: string;
        password: string;
        package: string;
    }>({ for: null, email: '', password: '', package: 'none' });
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    // A newly read backup starts with what it says about the account.
    if (account && inspected && form.for !== inspected.name) {
        setForm({
            for: inspected.name,
            email: account.email ?? '',
            password: '',
            package:
                account.package_id !== null
                    ? String(account.package_id)
                    : 'none',
        });
    }

    const submit = () => {
        setProcessing(true);
        setErrors({});
        router.post(
            action,
            {
                email: form.email || null,
                password: form.password || null,
                package_id:
                    form.package === 'none' ? null : Number(form.package),
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
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="rounded-2xl sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Buat lagi akun {backup.username}</DialogTitle>
                    <DialogDescription className="font-mono text-xs">
                        {backup.name}
                    </DialogDescription>
                </DialogHeader>

                {inspected === null ? (
                    <div className="grid gap-3">
                        <Skeleton className="h-5 w-2/3" />
                        <Skeleton className="h-5 w-1/2" />
                        <Skeleton className="h-5 w-3/5" />
                    </div>
                ) : !account ? (
                    <p className="text-sm text-destructive">
                        {inspected.error}
                    </p>
                ) : (
                    <div className="grid gap-4">
                        <p className="rounded-xl border bg-muted/40 px-4 py-3 text-sm">
                            Akun ini sudah dihapus. Akun dibuat lagi dengan
                            username yang sama untuk{' '}
                            <strong>{account.owner}</strong>, lalu domain (
                            {account.domains.length}), database (
                            {'databases' in inspected
                                ? inspected.databases.length
                                : 0}
                            ), akun email (
                            {'mailboxes' in inspected
                                ? inspected.mailboxes.length
                                : 0}
                            ), forwarder, cron job, file, dan isi email kembali.
                            Akun FTP, SSH key, redirect, dan record DNS buatan
                            sendiri perlu diatur lagi.
                        </p>

                        <div className="grid gap-2">
                            <Label htmlFor="restore-account-email">Email</Label>
                            <Input
                                id="restore-account-email"
                                type="email"
                                value={form.email}
                                onChange={(event) =>
                                    setForm({
                                        ...form,
                                        email: event.target.value,
                                    })
                                }
                                aria-invalid={!!errors.email || undefined}
                            />
                            <InputError message={errors.email} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="restore-account-password">
                                Password panel
                            </Label>
                            <GeneratedPasswordInput
                                id="restore-account-password"
                                value={form.password}
                                onChange={(password) =>
                                    setForm({ ...form, password })
                                }
                                required={!account.has_password}
                                placeholder={
                                    account.has_password
                                        ? 'Kosongkan untuk memakai password lama'
                                        : 'Password baru'
                                }
                                invalid={!!errors.password}
                            />
                            <InputError message={errors.password} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="restore-account-package">
                                Paket
                            </Label>
                            <Select
                                value={form.package}
                                onValueChange={(value) =>
                                    setForm({ ...form, package: value })
                                }
                            >
                                <SelectTrigger id="restore-account-package">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent className="rounded-xl">
                                    <SelectItem value="none">
                                        Tanpa paket
                                    </SelectItem>
                                    {account.packages.map((item) => (
                                        <SelectItem
                                            key={item.id}
                                            value={String(item.id)}
                                        >
                                            {item.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <p className="text-xs text-muted-foreground">
                                Pastikan paketnya cukup untuk isi backup
                                {backup.size !== null &&
                                    ` (${formatBytes(backup.size)} terkompres)`}
                                ; yang melewati batas paket tidak ikut kembali.
                            </p>
                            <InputError
                                message={errors.package_id ?? errors.domain}
                            />
                        </div>

                        <InputError message={errors.backup ?? errors.account} />
                    </div>
                )}

                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button variant="outline">Batal</Button>
                    </DialogClose>
                    <Button onClick={submit} disabled={!account || processing}>
                        {processing ? <Spinner /> : <UserPlus />}
                        Buat akun lagi
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
