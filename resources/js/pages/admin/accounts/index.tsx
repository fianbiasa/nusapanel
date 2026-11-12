import { Form, Head, Link, usePage, usePoll } from '@inertiajs/react';
import {
    MoreHorizontal,
    Package,
    Plus,
    Search,
    ShieldCheck,
    ShieldOff,
    Trash2,
    Users,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import InputError from '@/components/input-error';
import { PackageSelect } from '@/components/package-select';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { Panel, tableStyles } from '@/components/panel';
import { StatusBadge } from '@/components/status-badge';
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
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';
import { formatDateTime, formatDiskUsage, roleLabels } from '@/lib/format';
import {
    bulk,
    create,
    destroy,
    index,
    suspend,
    unsuspend,
} from '@/routes/admin/accounts';
import { update as updatePackage } from '@/routes/admin/accounts/package';
import type { AccountStatus, PackageOption, Paginated, Role } from '@/types';

type AccountRow = {
    id: number;
    username: string;
    name: string;
    email: string;
    role: Role;
    status: AccountStatus;
    suspend_reason: string | null;
    owner: string | null;
    package_id: number | null;
    package: string | null;
    domain: string | null;
    /** Megabytes; `used_mb` is null until the server measured the account. */
    disk: { used_mb: number | null; limit_mb: number | null };
    created_at: string | null;
};

type ActionType = 'suspend' | 'unsuspend' | 'delete' | 'package';

type AccountAction =
    | { type: ActionType; account: AccountRow }
    | { type: ActionType; bulk: number[] };

type Props = {
    accounts: Paginated<AccountRow>;
    packages: PackageOption[];
    filters: { search: string; status: AccountStatus | ''; package: string };
};

/** The statuses the list can be narrowed to, as the status badges name them. */
const statusFilters: { value: AccountStatus; label: string }[] = [
    { value: 'active', label: 'Aktif' },
    { value: 'suspended', label: 'Suspend' },
    { value: 'pending', label: 'Disiapkan' },
    { value: 'failed', label: 'Gagal' },
    { value: 'deleting', label: 'Dihapus' },
];

const filterTriggerClass = 'h-10 w-36 rounded-xl bg-card';

const busyStatuses: AccountStatus[] = ['pending', 'deleting'];

const deletableStatuses: AccountStatus[] = ['active', 'suspended', 'failed'];

export default function AccountsIndex({ accounts, packages, filters }: Props) {
    const { auth } = usePage().props;
    const getInitials = useInitials();
    const [action, setAction] = useState<AccountAction | null>(null);
    const [dialogOpen, setDialogOpen] = useState(false);
    const [selected, setSelected] = useState<number[]>([]);
    const [statusFilter, setStatusFilter] = useState(filters.status || 'all');
    const [packageFilter, setPackageFilter] = useState(
        filters.package || 'all',
    );
    const isFiltered =
        filters.search !== '' ||
        filters.status !== '' ||
        filters.package !== '';

    // Refresh the list while the agent is still creating or deleting accounts.
    const hasBusyAccounts = accounts.data.some((account) =>
        busyStatuses.includes(account.status),
    );
    const { start, stop } = usePoll(
        3000,
        { only: ['accounts'] },
        { autoStart: false },
    );

    useEffect(() => {
        if (hasBusyAccounts) {
            start();
        } else {
            stop();
        }
    }, [hasBusyAccounts, start, stop]);

    const openDialog = (type: ActionType, account: AccountRow) => {
        setAction({ type, account });
        setDialogOpen(true);
    };

    const isProtected = (account: AccountRow) =>
        account.role === 'admin' || account.id === auth.user.id;

    // Accounts that can be picked for a bulk action; the server checks each
    // one again and skips those the action does not fit.
    const isSelectable = (account: AccountRow) =>
        !isProtected(account) && account.status !== 'deleting';
    const selectable = accounts.data.filter(isSelectable);
    const selectedIds = selected.filter((id) =>
        selectable.some((account) => account.id === id),
    );
    const allSelected =
        selectable.length > 0 && selectedIds.length === selectable.length;

    const toggleAll = (checked: boolean) =>
        setSelected(checked ? selectable.map((account) => account.id) : []);
    const toggleOne = (id: number, checked: boolean) =>
        setSelected((current) =>
            checked
                ? [...current, id]
                : current.filter((selectedId) => selectedId !== id),
        );

    const openBulkDialog = (type: ActionType) => {
        setAction({ type, bulk: selectedIds });
        setDialogOpen(true);
    };

    const finishBulk = () => {
        setSelected([]);
        setDialogOpen(false);
    };

    return (
        <>
            <Head title="Accounts" />

            <PageHeader
                title="Accounts"
                description="Akun panel beserta user Linux-nya di server."
                actions={
                    <Button asChild size="lg">
                        <Link href={create()}>
                            <Plus />
                            Buat akun
                        </Link>
                    </Button>
                }
            />

            <Panel
                flush
                title="Daftar akun"
                description={`${accounts.total} akun`}
                action={
                    <Form
                        {...index.form()}
                        options={{ preserveState: true, replace: true }}
                        className="hidden w-full max-w-2xl items-center gap-2 md:flex"
                    >
                        {statusFilter !== 'all' && (
                            <input
                                type="hidden"
                                name="status"
                                value={statusFilter}
                            />
                        )}
                        {packageFilter !== 'all' && (
                            <input
                                type="hidden"
                                name="package"
                                value={packageFilter}
                            />
                        )}
                        <Select
                            value={statusFilter}
                            onValueChange={setStatusFilter}
                        >
                            <SelectTrigger
                                aria-label="Filter status"
                                className={filterTriggerClass}
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent className="rounded-xl">
                                <SelectItem value="all">
                                    Semua status
                                </SelectItem>
                                {statusFilters.map((option) => (
                                    <SelectItem
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Select
                            value={packageFilter}
                            onValueChange={setPackageFilter}
                        >
                            <SelectTrigger
                                aria-label="Filter paket"
                                className={filterTriggerClass}
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent className="rounded-xl">
                                <SelectItem value="all">Semua paket</SelectItem>
                                {packages.map((option) => (
                                    <SelectItem
                                        key={option.id}
                                        value={String(option.id)}
                                    >
                                        {option.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <div className="relative flex-1">
                            <Search className="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                type="search"
                                name="search"
                                defaultValue={filters.search}
                                placeholder="Cari username, nama, atau email"
                                aria-label="Cari akun"
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
                {accounts.data.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 px-6 pt-4 pb-10 text-center">
                        <span className="flex size-14 items-center justify-center rounded-full bg-accent text-accent-foreground">
                            <Users className="size-6" />
                        </span>
                        <p className="max-w-sm text-sm text-muted-foreground">
                            {filters.search
                                ? `Tidak ada akun yang cocok dengan "${filters.search}".`
                                : isFiltered
                                  ? 'Tidak ada akun yang cocok dengan filter ini.'
                                  : 'Belum ada akun. Buat akun pertama untuk memulai.'}
                        </p>
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        {selectedIds.length > 0 && (
                            <BulkBar
                                count={selectedIds.length}
                                onAction={openBulkDialog}
                                onClear={() => setSelected([])}
                            />
                        )}
                        <table className={tableStyles.table}>
                            <thead className={tableStyles.head}>
                                <tr>
                                    <th className={`${tableStyles.th} w-10`}>
                                        <Checkbox
                                            aria-label="Pilih semua akun di halaman ini"
                                            checked={
                                                allSelected
                                                    ? true
                                                    : selectedIds.length > 0
                                                      ? 'indeterminate'
                                                      : false
                                            }
                                            onCheckedChange={(checked) =>
                                                toggleAll(checked === true)
                                            }
                                            disabled={selectable.length === 0}
                                        />
                                    </th>
                                    <th className={tableStyles.th}>Akun</th>
                                    <th className={tableStyles.th}>Domain</th>
                                    <th className={tableStyles.th}>Paket</th>
                                    <th className={tableStyles.th}>Disk</th>
                                    <th className={tableStyles.th}>Peran</th>
                                    <th className={tableStyles.th}>Status</th>
                                    <th className={tableStyles.th}>Pemilik</th>
                                    <th className={tableStyles.th}>Dibuat</th>
                                    <th className={tableStyles.th}>
                                        <span className="sr-only">Aksi</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {accounts.data.map((account) => (
                                    <tr
                                        key={account.id}
                                        className={tableStyles.row}
                                    >
                                        <td className={tableStyles.td}>
                                            <Checkbox
                                                aria-label={`Pilih ${account.username}`}
                                                checked={selectedIds.includes(
                                                    account.id,
                                                )}
                                                onCheckedChange={(checked) =>
                                                    toggleOne(
                                                        account.id,
                                                        checked === true,
                                                    )
                                                }
                                                disabled={
                                                    !isSelectable(account)
                                                }
                                            />
                                        </td>
                                        <td className={tableStyles.td}>
                                            <div className="flex items-center gap-3">
                                                <span className="flex size-9 shrink-0 items-center justify-center rounded-full rayxo-brand-gradient text-xs font-semibold text-white">
                                                    {getInitials(account.name)}
                                                </span>
                                                <div className="min-w-0">
                                                    <p className="truncate font-semibold">
                                                        {account.name}
                                                    </p>
                                                    <p className="truncate text-xs text-muted-foreground">
                                                        <code className="font-mono">
                                                            {account.username}
                                                        </code>{' '}
                                                        · {account.email}
                                                    </p>
                                                </div>
                                            </div>
                                        </td>
                                        <td className={tableStyles.td}>
                                            {account.domain ?? (
                                                <span className="text-muted-foreground">
                                                    —
                                                </span>
                                            )}
                                        </td>
                                        <td className={tableStyles.td}>
                                            {account.package ?? (
                                                <span className="text-muted-foreground">
                                                    —
                                                </span>
                                            )}
                                        </td>
                                        <td
                                            className={cn(
                                                tableStyles.td,
                                                'whitespace-nowrap',
                                                account.disk.used_mb === null &&
                                                    'text-muted-foreground',
                                            )}
                                        >
                                            {formatDiskUsage(
                                                account.disk.used_mb,
                                                account.disk.limit_mb,
                                            )}
                                        </td>
                                        <td className={tableStyles.td}>
                                            {roleLabels[account.role]}
                                        </td>
                                        <td className={tableStyles.td}>
                                            <StatusBadge
                                                status={account.status}
                                            />
                                            {account.status === 'suspended' &&
                                                account.suspend_reason && (
                                                    <p className="mt-1.5 max-w-xs text-xs text-muted-foreground">
                                                        {account.suspend_reason}
                                                    </p>
                                                )}
                                        </td>
                                        <td
                                            className={`${tableStyles.td} text-muted-foreground`}
                                        >
                                            {account.owner ?? '—'}
                                        </td>
                                        <td
                                            className={`${tableStyles.td} whitespace-nowrap text-muted-foreground`}
                                        >
                                            {account.created_at
                                                ? formatDateTime(
                                                      account.created_at,
                                                  )
                                                : '—'}
                                        </td>
                                        <td className={tableStyles.td}>
                                            <RowActions
                                                account={account}
                                                isProtected={isProtected(
                                                    account,
                                                )}
                                                onAction={openDialog}
                                            />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {accounts.last_page > 1 && (
                    <div className="border-t px-6 py-4">
                        <Pagination page={accounts} />
                    </div>
                )}
            </Panel>

            <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                <DialogContent className="rounded-2xl">
                    {action && 'bulk' in action && (
                        <BulkActionForm
                            key={`bulk-${action.type}`}
                            type={action.type}
                            ids={action.bulk}
                            packages={packages}
                            onSuccess={finishBulk}
                        />
                    )}
                    {action &&
                        'account' in action &&
                        action.type === 'package' && (
                            <ChangePackageForm
                                key={`package-${action.account.id}`}
                                account={action.account}
                                packages={packages}
                                onSuccess={() => setDialogOpen(false)}
                            />
                        )}
                    {action &&
                        'account' in action &&
                        action.type !== 'package' && (
                            <AccountActionForm
                                key={`${action.type}-${action.account.id}`}
                                type={action.type}
                                account={action.account}
                                onSuccess={() => setDialogOpen(false)}
                            />
                        )}
                </DialogContent>
            </Dialog>
        </>
    );
}

function ChangePackageForm({
    account,
    packages,
    onSuccess,
}: {
    account: AccountRow;
    packages: PackageOption[];
    onSuccess: () => void;
}) {
    return (
        <Form
            {...updatePackage.form(account.id)}
            options={{ preserveScroll: true }}
            onSuccess={onSuccess}
            className="grid gap-4"
        >
            {({ processing, errors }) => (
                <>
                    <DialogHeader>
                        <DialogTitle>
                            Ganti paket {account.username}
                        </DialogTitle>
                        <DialogDescription>
                            Batas paket baru langsung berlaku. Kalau akun ini
                            sudah memakai lebih dari batas paket barunya, yang
                            sudah ada tetap jalan, tapi akun tidak bisa menambah
                            lagi.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="package_id">Paket</Label>
                        <PackageSelect
                            id="package_id"
                            packages={packages}
                            defaultValue={account.package_id}
                            invalid={Boolean(errors.package_id)}
                        />
                        <InputError message={errors.package_id} />
                    </div>

                    <InputError message={errors.account} />

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Batal
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={processing}>
                            {processing && <Spinner />}
                            Simpan paket
                        </Button>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}

function AccountActionForm({
    type,
    account,
    onSuccess,
}: {
    type: 'suspend' | 'unsuspend' | 'delete';
    account: AccountRow;
    onSuccess: () => void;
}) {
    const username = account.username;

    const copy = {
        suspend: {
            form: suspend.form(account.id),
            title: `Suspend ${username}?`,
            description: `${username} akan dikeluarkan dan tidak bisa masuk lagi. Semua website-nya menampilkan halaman "tidak tersedia" dan akunnya dikunci di server.`,
            confirm: 'Suspend akun',
            variant: 'destructive' as const,
        },
        unsuspend: {
            form: unsuspend.form(account.id),
            title: `Buka suspend ${username}?`,
            description: `${username} bisa masuk lagi, website-nya aktif kembali, dan kuncinya di server dibuka.`,
            confirm: 'Buka suspend',
            variant: 'default' as const,
        },
        delete: {
            form: destroy.form(account.id),
            title: `Hapus ${username}?`,
            description: `Akun ${username} beserta semua file dan website-nya dihapus dari server. Tindakan ini tidak bisa dibatalkan.`,
            confirm: 'Hapus akun',
            variant: 'destructive' as const,
        },
    }[type];

    return (
        <Form
            {...copy.form}
            options={{ preserveScroll: true }}
            onSuccess={onSuccess}
            className="grid gap-4"
        >
            {({ processing, errors }) => (
                <>
                    <DialogHeader>
                        <DialogTitle>{copy.title}</DialogTitle>
                        <DialogDescription>
                            {copy.description}
                        </DialogDescription>
                    </DialogHeader>

                    {type === 'suspend' && (
                        <div className="grid gap-2">
                            <Label htmlFor="reason">Alasan (opsional)</Label>
                            <Input
                                id="reason"
                                name="reason"
                                maxLength={255}
                                placeholder="mis. Tagihan belum dibayar"
                                autoComplete="off"
                            />
                            <InputError message={errors.reason} />
                        </div>
                    )}

                    <InputError message={errors.account} />

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Batal
                            </Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            variant={copy.variant}
                            disabled={processing}
                        >
                            {processing && <Spinner />}
                            {copy.confirm}
                        </Button>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}

/** The "⋯" menu of one account row, with the actions its status allows. */
function RowActions({
    account,
    isProtected,
    onAction,
}: {
    account: AccountRow;
    isProtected: boolean;
    onAction: (type: ActionType, account: AccountRow) => void;
}) {
    const canChangePackage =
        account.role === 'user' && account.status !== 'deleting';
    const canSuspend = account.status === 'active' && !isProtected;
    const canUnsuspend = account.status === 'suspended';
    const canDelete =
        deletableStatuses.includes(account.status) && !isProtected;

    if (!canChangePackage && !canSuspend && !canUnsuspend && !canDelete) {
        return null;
    }

    return (
        <div className="flex justify-end">
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label={`Aksi untuk ${account.username}`}
                    >
                        <MoreHorizontal className="size-5" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    align="end"
                    className="min-w-44 rounded-xl"
                >
                    {canChangePackage && (
                        <DropdownMenuItem
                            onSelect={() => onAction('package', account)}
                        >
                            <Package /> Ganti paket
                        </DropdownMenuItem>
                    )}
                    {canSuspend && (
                        <DropdownMenuItem
                            onSelect={() => onAction('suspend', account)}
                        >
                            <ShieldOff /> Suspend
                        </DropdownMenuItem>
                    )}
                    {canUnsuspend && (
                        <DropdownMenuItem
                            onSelect={() => onAction('unsuspend', account)}
                        >
                            <ShieldCheck /> Buka suspend
                        </DropdownMenuItem>
                    )}
                    {canDelete && (
                        <DropdownMenuItem
                            variant="destructive"
                            onSelect={() => onAction('delete', account)}
                        >
                            <Trash2 /> Hapus akun
                        </DropdownMenuItem>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    );
}

/** What to do with the accounts picked on this page. */
function BulkBar({
    count,
    onAction,
    onClear,
}: {
    count: number;
    onAction: (type: ActionType) => void;
    onClear: () => void;
}) {
    return (
        <div className="flex flex-wrap items-center gap-2 border-b bg-muted/40 px-6 py-3">
            <span className="mr-auto text-sm font-semibold">
                {count} akun dipilih
            </span>
            <Button
                variant="outline"
                size="sm"
                onClick={() => onAction('package')}
            >
                <Package /> Ganti paket
            </Button>
            <Button
                variant="outline"
                size="sm"
                onClick={() => onAction('suspend')}
            >
                <ShieldOff /> Suspend
            </Button>
            <Button
                variant="outline"
                size="sm"
                onClick={() => onAction('unsuspend')}
            >
                <ShieldCheck /> Buka suspend
            </Button>
            <Button
                variant="destructive"
                size="sm"
                onClick={() => onAction('delete')}
            >
                <Trash2 /> Hapus
            </Button>
            <Button variant="ghost" size="sm" onClick={onClear}>
                Batal pilih
            </Button>
        </div>
    );
}

/** The confirmation of a bulk action, with what the action needs. */
function BulkActionForm({
    type,
    ids,
    packages,
    onSuccess,
}: {
    type: ActionType;
    ids: number[];
    packages: PackageOption[];
    onSuccess: () => void;
}) {
    const [confirmation, setConfirmation] = useState('');
    const count = ids.length;

    const copy = {
        package: {
            title: `Ganti paket ${count} akun?`,
            description:
                'Batas paket baru langsung berlaku. Akun yang sudah memakai lebih dari batasnya tetap jalan, tapi tidak bisa menambah lagi.',
            confirm: 'Simpan paket',
            variant: 'default' as const,
        },
        suspend: {
            title: `Suspend ${count} akun?`,
            description:
                'Akun-akun ini dikeluarkan dan tidak bisa masuk lagi, website-nya menampilkan halaman "tidak tersedia", dan akunnya dikunci di server. Akun yang tidak aktif dilewati.',
            confirm: 'Suspend akun',
            variant: 'destructive' as const,
        },
        unsuspend: {
            title: `Buka suspend ${count} akun?`,
            description:
                'Akun-akun ini bisa masuk lagi dan website-nya aktif kembali. Akun yang tidak di-suspend dilewati.',
            confirm: 'Buka suspend',
            variant: 'default' as const,
        },
        delete: {
            title: `Hapus ${count} akun?`,
            description:
                'Akun-akun ini beserta semua file, website, database dan email-nya dihapus dari server. Tindakan ini tidak bisa dibatalkan.',
            confirm: 'Hapus akun',
            variant: 'destructive' as const,
        },
    }[type];

    const isConfirmed = type !== 'delete' || confirmation.trim() === 'HAPUS';

    return (
        <Form
            {...bulk.form()}
            options={{ preserveScroll: true }}
            onSuccess={onSuccess}
            className="grid gap-4"
        >
            {({ processing, errors }) => (
                <>
                    <DialogHeader>
                        <DialogTitle>{copy.title}</DialogTitle>
                        <DialogDescription>
                            {copy.description}
                        </DialogDescription>
                    </DialogHeader>

                    <input type="hidden" name="action" value={type} />
                    {ids.map((id) => (
                        <input key={id} type="hidden" name="ids[]" value={id} />
                    ))}

                    {type === 'suspend' && (
                        <div className="grid gap-2">
                            <Label htmlFor="bulk-reason">
                                Alasan (opsional)
                            </Label>
                            <Input
                                id="bulk-reason"
                                name="reason"
                                maxLength={255}
                                placeholder="mis. Tagihan belum dibayar"
                                autoComplete="off"
                            />
                            <InputError message={errors.reason} />
                        </div>
                    )}

                    {type === 'package' && (
                        <div className="grid gap-2">
                            <Label htmlFor="bulk-package">Paket</Label>
                            <PackageSelect
                                id="bulk-package"
                                packages={packages}
                                defaultValue={packages[0]?.id ?? null}
                                invalid={Boolean(errors.package_id)}
                            />
                            <InputError message={errors.package_id} />
                        </div>
                    )}

                    {type === 'delete' && (
                        <div className="grid gap-2">
                            <Label htmlFor="bulk-confirmation">
                                Ketik <span className="font-mono">HAPUS</span>{' '}
                                untuk mengonfirmasi
                            </Label>
                            <Input
                                id="bulk-confirmation"
                                value={confirmation}
                                onChange={(event) =>
                                    setConfirmation(event.target.value)
                                }
                                autoComplete="off"
                                spellCheck={false}
                            />
                        </div>
                    )}

                    <InputError message={errors.ids ?? errors.action} />

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Batal
                            </Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            variant={copy.variant}
                            disabled={processing || !isConfirmed}
                        >
                            {processing && <Spinner />}
                            {copy.confirm}
                        </Button>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}
