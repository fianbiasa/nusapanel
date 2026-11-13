import { Form, Head, Link, usePage, usePoll } from '@inertiajs/react';
import {
    ArrowRightLeft,
    Boxes,
    LogIn,
    MoreHorizontal,
    Network,
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
import { accountRoutes } from '@/lib/scoped-routes';
import type { AccountScope } from '@/lib/scoped-routes';
import { update as updateOwner } from '@/routes/admin/accounts/owner';
import { update as updateResellerPackage } from '@/routes/admin/accounts/reseller-package';
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
    owner_id: number | null;
    /** Whose packages the account goes on: "admin", or the reseller's id. */
    packages_of: string;
    package_id: number | null;
    package: string | null;
    reseller_package_id: number | null;
    reseller_package: string | null;
    /** For a reseller, how many accounts it owns. */
    owned_accounts: number | null;
    domain: string | null;
    /** The account's own addresses; null is the server's. */
    ipv4_address_id: number | null;
    ipv6_address_id: number | null;
    ipv4: string | null;
    ipv6: string | null;
    /** Megabytes; `used_mb` is null until the server measured the account. */
    disk: { used_mb: number | null; limit_mb: number | null };
    created_at: string | null;
};

type ActionType =
    | 'suspend'
    | 'unsuspend'
    | 'delete'
    | 'package'
    | 'login-as'
    | 'transfer'
    | 'reseller-package'
    | 'addresses';

type Routes = ReturnType<typeof accountRoutes>;

type OwnerOption = { id: number; username: string; role: Role };

/**
 * An extra IP address of the server: `of` says whose accounts may have it
 * (like `packages_of`), `used_by` which accounts have it; a dedicated one
 * only goes to one account.
 */
type IpOption = {
    id: number;
    address: string;
    version: 4 | 6;
    of: string;
    dedicated: boolean;
    used_by: number[];
};

type BulkActionType = 'suspend' | 'unsuspend' | 'delete' | 'package';

type AccountAction =
    | { type: ActionType; account: AccountRow }
    | { type: BulkActionType; bulk: number[] };

type Props = {
    scope: AccountScope;
    accounts: Paginated<AccountRow>;
    /** The packages the signed-in account's own accounts go on. */
    packages: PackageOption[];
    /** For administrators: each owner's packages, see `packages_of`. */
    packageGroups: Record<string, PackageOption[]> | null;
    resellerPackages: PackageOption[] | null;
    owners: OwnerOption[] | null;
    ipAddresses: IpOption[];
    filters: {
        search: string;
        status: AccountStatus | '';
        package: string;
        role: Role | '';
        owner: string;
        sort: AccountSort;
    };
};

type AccountSort = 'newest' | 'oldest' | 'username' | 'disk';

/** The orders the list can be sorted in. */
const sortOptions: { value: AccountSort; label: string }[] = [
    { value: 'newest', label: 'Terbaru' },
    { value: 'oldest', label: 'Terlama' },
    { value: 'username', label: 'Username A–Z' },
    { value: 'disk', label: 'Disk terbesar' },
];

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

export default function AccountsIndex({
    scope,
    accounts,
    packages,
    packageGroups,
    resellerPackages,
    owners,
    ipAddresses,
    filters,
}: Props) {
    const { auth } = usePage().props;
    const routes = accountRoutes(scope);
    const isAdmin = scope === 'admin';
    const [roleFilter, setRoleFilter] = useState(filters.role || 'all');
    const [ownerFilter, setOwnerFilter] = useState(filters.owner || 'all');
    const [sort, setSort] = useState<AccountSort>(filters.sort);
    const packagesFor = (account: AccountRow): PackageOption[] =>
        isAdmin ? (packageGroups?.[account.packages_of] ?? []) : packages;
    const addressesFor = (account: AccountRow): IpOption[] =>
        ipAddresses.filter(
            (option) =>
                option.of === account.packages_of &&
                (!option.dedicated ||
                    option.used_by.every((id) => id === account.id)),
        );
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
        filters.package !== '' ||
        filters.role !== '' ||
        filters.owner !== '';

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

    const openBulkDialog = (type: BulkActionType) => {
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
                description={
                    isAdmin
                        ? 'Akun panel: akun hosting beserta user Linux-nya di server, reseller, dan administrator.'
                        : 'Akun hosting milik Anda, dibatasi paket reseller Anda.'
                }
                actions={
                    <Button asChild size="lg">
                        <Link href={routes.create()}>
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
                        {...routes.index.form()}
                        options={{ preserveState: true, replace: true }}
                        className="hidden w-full max-w-3xl items-center gap-2 md:flex"
                    >
                        {sort !== 'newest' && (
                            <input type="hidden" name="sort" value={sort} />
                        )}
                        {roleFilter !== 'all' && (
                            <input
                                type="hidden"
                                name="role"
                                value={roleFilter}
                            />
                        )}
                        <Select
                            value={sort}
                            onValueChange={(value) =>
                                setSort(value as AccountSort)
                            }
                        >
                            <SelectTrigger
                                aria-label="Urutkan"
                                className={filterTriggerClass}
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent className="rounded-xl">
                                {sortOptions.map((option) => (
                                    <SelectItem
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {ownerFilter !== 'all' && (
                            <input
                                type="hidden"
                                name="owner"
                                value={ownerFilter}
                            />
                        )}
                        {isAdmin && (
                            <Select
                                value={roleFilter}
                                onValueChange={setRoleFilter}
                            >
                                <SelectTrigger
                                    aria-label="Filter peran"
                                    className={filterTriggerClass}
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent className="rounded-xl">
                                    <SelectItem value="all">
                                        Semua peran
                                    </SelectItem>
                                    {(
                                        ['user', 'reseller', 'admin'] as Role[]
                                    ).map((role) => (
                                        <SelectItem key={role} value={role}>
                                            {roleLabels[role]}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        )}
                        {isAdmin && owners && (
                            <Select
                                value={ownerFilter}
                                onValueChange={setOwnerFilter}
                            >
                                <SelectTrigger
                                    aria-label="Filter pemilik"
                                    className={filterTriggerClass}
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent className="rounded-xl">
                                    <SelectItem value="all">
                                        Semua pemilik
                                    </SelectItem>
                                    {owners.map((owner) => (
                                        <SelectItem
                                            key={owner.id}
                                            value={String(owner.id)}
                                        >
                                            {owner.username}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        )}
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
                                    {isAdmin && (
                                        <th className={tableStyles.th}>
                                            Peran
                                        </th>
                                    )}
                                    <th className={tableStyles.th}>Status</th>
                                    {isAdmin && (
                                        <th className={tableStyles.th}>
                                            Pemilik
                                        </th>
                                    )}
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
                                            {(account.ipv4 ?? account.ipv6) && (
                                                <p className="font-mono text-xs text-muted-foreground">
                                                    {[
                                                        account.ipv4,
                                                        account.ipv6,
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' · ')}
                                                </p>
                                            )}
                                        </td>
                                        <td className={tableStyles.td}>
                                            {account.role === 'reseller' ? (
                                                <>
                                                    {account.reseller_package ??
                                                        'Tanpa batas'}
                                                    <p className="text-xs text-muted-foreground">
                                                        {account.owned_accounts ??
                                                            0}{' '}
                                                        akun
                                                    </p>
                                                </>
                                            ) : (
                                                (account.package ?? (
                                                    <span className="text-muted-foreground">
                                                        —
                                                    </span>
                                                ))
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
                                        {isAdmin && (
                                            <td className={tableStyles.td}>
                                                {roleLabels[account.role]}
                                            </td>
                                        )}
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
                                        {isAdmin && (
                                            <td
                                                className={`${tableStyles.td} text-muted-foreground`}
                                            >
                                                {account.owner ?? '—'}
                                            </td>
                                        )}
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
                                                scope={scope}
                                                account={account}
                                                isProtected={isProtected(
                                                    account,
                                                )}
                                                canLoginAs={
                                                    auth.impersonator ===
                                                        null &&
                                                    account.id !==
                                                        auth.user.id &&
                                                    account.role !== 'admin' &&
                                                    account.status === 'active'
                                                }
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
                            routes={routes}
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
                                routes={routes}
                                account={action.account}
                                packages={packagesFor(action.account)}
                                onSuccess={() => setDialogOpen(false)}
                            />
                        )}
                    {action &&
                        'account' in action &&
                        action.type === 'addresses' && (
                            <ChangeAddressesForm
                                key={`addresses-${action.account.id}`}
                                routes={routes}
                                account={action.account}
                                addresses={addressesFor(action.account)}
                                onSuccess={() => setDialogOpen(false)}
                            />
                        )}
                    {action &&
                        'account' in action &&
                        action.type === 'login-as' && (
                            <LoginAsForm
                                key={`login-as-${action.account.id}`}
                                routes={routes}
                                account={action.account}
                            />
                        )}
                    {action &&
                        'account' in action &&
                        action.type === 'transfer' &&
                        owners &&
                        packageGroups && (
                            <TransferForm
                                key={`transfer-${action.account.id}`}
                                account={action.account}
                                owners={owners}
                                packageGroups={packageGroups}
                                onSuccess={() => setDialogOpen(false)}
                            />
                        )}
                    {action &&
                        'account' in action &&
                        action.type === 'reseller-package' &&
                        resellerPackages && (
                            <ResellerPackageForm
                                key={`reseller-package-${action.account.id}`}
                                account={action.account}
                                packages={resellerPackages}
                                onSuccess={() => setDialogOpen(false)}
                            />
                        )}
                    {action &&
                        'account' in action &&
                        (action.type === 'suspend' ||
                            action.type === 'unsuspend' ||
                            action.type === 'delete') && (
                            <AccountActionForm
                                key={`${action.type}-${action.account.id}`}
                                routes={routes}
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
    routes,
    account,
    packages,
    onSuccess,
}: {
    routes: Routes;
    account: AccountRow;
    packages: PackageOption[];
    onSuccess: () => void;
}) {
    return (
        <Form
            {...routes.updatePackage.form(account.id)}
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

/** The select's value for "the server's address"; Radix items cannot be empty. */
const serverAddress = 'server';

/**
 * Gives a hosting account its own IPv4 and IPv6 address, or the server's.
 */
function ChangeAddressesForm({
    routes,
    account,
    addresses,
    onSuccess,
}: {
    routes: Routes;
    account: AccountRow;
    addresses: IpOption[];
    onSuccess: () => void;
}) {
    const [ipv4, setIpv4] = useState(
        account.ipv4_address_id === null
            ? serverAddress
            : String(account.ipv4_address_id),
    );
    const [ipv6, setIpv6] = useState(
        account.ipv6_address_id === null
            ? serverAddress
            : String(account.ipv6_address_id),
    );
    const fields = [
        {
            name: 'ipv4_address_id',
            label: 'IPv4',
            none: 'Alamat server',
            value: ipv4,
            onChange: setIpv4,
            options: addresses.filter((option) => option.version === 4),
        },
        {
            name: 'ipv6_address_id',
            label: 'IPv6',
            none: 'Semua alamat IPv6 server',
            value: ipv6,
            onChange: setIpv6,
            options: addresses.filter((option) => option.version === 6),
        },
    ] as const;

    return (
        <Form
            {...routes.updateAddresses.form(account.id)}
            options={{ preserveScroll: true }}
            onSuccess={onSuccess}
            className="grid gap-4"
        >
            {({ processing, errors }) => (
                <>
                    <DialogHeader>
                        <DialogTitle>Alamat IP {account.username}</DialogTitle>
                        <DialogDescription>
                            Website akun ini dilayani di alamat yang dipilih
                            saja, dan record DNS website-nya (domain, www,
                            subdomain) ikut pindah. Email tetap di alamat
                            server.
                        </DialogDescription>
                    </DialogHeader>

                    {fields.map((field) => (
                        <div key={field.name} className="grid gap-2">
                            <Label htmlFor={field.name}>{field.label}</Label>
                            <input
                                type="hidden"
                                name={field.name}
                                value={
                                    field.value === serverAddress
                                        ? ''
                                        : field.value
                                }
                            />
                            <Select
                                value={field.value}
                                onValueChange={field.onChange}
                            >
                                <SelectTrigger
                                    id={field.name}
                                    aria-invalid={
                                        errors[field.name] ? true : undefined
                                    }
                                    className="w-full rounded-xl bg-card px-3.5 data-[size=default]:h-11 md:max-w-sm"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent className="rounded-xl">
                                    <SelectItem value={serverAddress}>
                                        {field.none}
                                    </SelectItem>
                                    {field.options.map((option) => (
                                        <SelectItem
                                            key={option.id}
                                            value={String(option.id)}
                                        >
                                            {option.address}
                                            {option.dedicated
                                                ? ' (dedicated)'
                                                : ' (shared)'}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors[field.name]} />
                        </div>
                    ))}

                    {addresses.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            Belum ada alamat IP yang bisa diberikan ke akun ini.
                            Tambahkan dulu di menu Alamat IP.
                        </p>
                    )}

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Batal
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={processing}>
                            {processing && <Spinner />}
                            Simpan alamat
                        </Button>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}

function AccountActionForm({
    routes,
    type,
    account,
    onSuccess,
}: {
    routes: Routes;
    type: 'suspend' | 'unsuspend' | 'delete';
    account: AccountRow;
    onSuccess: () => void;
}) {
    const username = account.username;
    const isReseller = account.role === 'reseller';

    const copy = {
        suspend: {
            form: routes.suspend.form(account.id),
            title: `Suspend ${username}?`,
            description: isReseller
                ? `${username} akan dikeluarkan dan tidak bisa masuk lagi. Akun-akun hosting miliknya tetap berjalan; suspend akun-akun itu terpisah kalau perlu.`
                : `${username} akan dikeluarkan dan tidak bisa masuk lagi. Semua website-nya menampilkan halaman "tidak tersedia" dan akunnya dikunci di server.`,
            confirm: 'Suspend akun',
            variant: 'destructive' as const,
        },
        unsuspend: {
            form: routes.unsuspend.form(account.id),
            title: `Buka suspend ${username}?`,
            description: isReseller
                ? `${username} bisa masuk lagi.`
                : `${username} bisa masuk lagi, website-nya aktif kembali, dan kuncinya di server dibuka.`,
            confirm: 'Buka suspend',
            variant: 'default' as const,
        },
        delete: {
            form: routes.destroy.form(account.id),
            title: `Hapus ${username}?`,
            description: isReseller
                ? `Reseller ${username} beserta paket-paketnya dihapus dari panel. Reseller harus sudah tidak punya akun hosting; pindahkan atau hapus akun-akunnya dulu.`
                : `Akun ${username} beserta semua file dan website-nya dihapus dari server. Tindakan ini tidak bisa dibatalkan.`,
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
    scope,
    account,
    isProtected,
    canLoginAs,
    onAction,
}: {
    scope: AccountScope;
    account: AccountRow;
    isProtected: boolean;
    canLoginAs: boolean;
    onAction: (type: ActionType, account: AccountRow) => void;
}) {
    const canChangePackage =
        account.role === 'user' && account.status !== 'deleting';
    const canTransfer = scope === 'admin' && canChangePackage;
    const canChangeResellerPackage =
        scope === 'admin' && account.role === 'reseller';
    const canSuspend = account.status === 'active' && !isProtected;
    const canUnsuspend = account.status === 'suspended';
    const canDelete =
        deletableStatuses.includes(account.status) && !isProtected;

    if (
        !canLoginAs &&
        !canChangePackage &&
        !canChangeResellerPackage &&
        !canSuspend &&
        !canUnsuspend &&
        !canDelete
    ) {
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
                    {canLoginAs && (
                        <DropdownMenuItem
                            onSelect={() => onAction('login-as', account)}
                        >
                            <LogIn /> Masuk sebagai
                        </DropdownMenuItem>
                    )}
                    {canChangePackage && (
                        <DropdownMenuItem
                            onSelect={() => onAction('package', account)}
                        >
                            <Package /> Ganti paket
                        </DropdownMenuItem>
                    )}
                    {canChangeResellerPackage && (
                        <DropdownMenuItem
                            onSelect={() =>
                                onAction('reseller-package', account)
                            }
                        >
                            <Boxes /> Ganti paket reseller
                        </DropdownMenuItem>
                    )}
                    {canChangePackage && (
                        <DropdownMenuItem
                            onSelect={() => onAction('addresses', account)}
                        >
                            <Network /> Alamat IP
                        </DropdownMenuItem>
                    )}
                    {canTransfer && (
                        <DropdownMenuItem
                            onSelect={() => onAction('transfer', account)}
                        >
                            <ArrowRightLeft /> Pindah pemilik
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
    onAction: (type: BulkActionType) => void;
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
    routes,
    type,
    ids,
    packages,
    onSuccess,
}: {
    routes: Routes;
    type: BulkActionType;
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
            {...routes.bulk.form()}
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

/**
 * Confirm signing in as the account. The page reloads as that account; a
 * bar at the top leads back.
 */
function LoginAsForm({
    routes,
    account,
}: {
    routes: Routes;
    account: AccountRow;
}) {
    return (
        <Form {...routes.loginAs.form(account.id)} className="grid gap-4">
            {({ processing, errors }) => (
                <>
                    <DialogHeader>
                        <DialogTitle>
                            Masuk sebagai {account.username}?
                        </DialogTitle>
                        <DialogDescription>
                            Anda melihat dan mengelola panel persis seperti{' '}
                            {account.username}. Semua yang Anda lakukan tercatat
                            di audit log atas nama Anda. Kata sandi, email, dan
                            2FA {account.username} tidak bisa diubah dari sana.
                        </DialogDescription>
                    </DialogHeader>

                    <InputError message={errors.account} />

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Batal
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={processing}>
                            {processing ? <Spinner /> : <LogIn />}
                            Masuk sebagai {account.username}
                        </Button>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}

/**
 * Move a hosting account to another administrator or reseller, on one of
 * the new owner's packages.
 */
function TransferForm({
    account,
    owners,
    packageGroups,
    onSuccess,
}: {
    account: AccountRow;
    owners: OwnerOption[];
    packageGroups: Record<string, PackageOption[]>;
    onSuccess: () => void;
}) {
    const choices = owners.filter((owner) => owner.id !== account.owner_id);
    const [ownerId, setOwnerId] = useState(
        choices[0] ? String(choices[0].id) : '',
    );
    const owner = choices.find((choice) => String(choice.id) === ownerId);
    const packages = owner
        ? (packageGroups[
              owner.role === 'reseller' ? String(owner.id) : 'admin'
          ] ?? [])
        : [];

    return (
        <Form
            {...updateOwner.form(account.id)}
            options={{ preserveScroll: true }}
            onSuccess={onSuccess}
            className="grid gap-4"
        >
            {({ processing, errors }) => (
                <>
                    <DialogHeader>
                        <DialogTitle>
                            Pindahkan {account.username} ke pemilik lain
                        </DialogTitle>
                        <DialogDescription>
                            Akun ini dikelola pemilik barunya, dengan salah satu
                            paket milik pemilik itu. Website dan datanya tidak
                            berubah. Jatah reseller tujuan tetap berlaku.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="owner_id">Pemilik baru</Label>
                        <input type="hidden" name="owner_id" value={ownerId} />
                        <Select value={ownerId} onValueChange={setOwnerId}>
                            <SelectTrigger
                                id="owner_id"
                                aria-invalid={
                                    errors.owner_id ? true : undefined
                                }
                            >
                                <SelectValue placeholder="Pilih pemilik" />
                            </SelectTrigger>
                            <SelectContent className="rounded-xl">
                                {choices.map((choice) => (
                                    <SelectItem
                                        key={choice.id}
                                        value={String(choice.id)}
                                    >
                                        {choice.username} ·{' '}
                                        {roleLabels[choice.role]}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.owner_id} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="transfer-package">Paket</Label>
                        <PackageSelect
                            key={ownerId}
                            id="transfer-package"
                            packages={packages}
                            defaultValue={packages[0]?.id ?? null}
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
                        <Button
                            type="submit"
                            disabled={processing || ownerId === ''}
                        >
                            {processing && <Spinner />}
                            Pindahkan akun
                        </Button>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}

/** Put a reseller on another reseller package, or none (no limits). */
function ResellerPackageForm({
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
            {...updateResellerPackage.form(account.id)}
            options={{ preserveScroll: true }}
            onSuccess={onSuccess}
            className="grid gap-4"
        >
            {({ processing, errors }) => (
                <>
                    <DialogHeader>
                        <DialogTitle>
                            Ganti paket reseller {account.username}
                        </DialogTitle>
                        <DialogDescription>
                            Jatah baru langsung berlaku. Kalau reseller ini
                            sudah membagikan lebih dari jatah barunya,
                            akun-akunnya tetap jalan, tapi ia tidak bisa
                            menambah lagi.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="reseller_package_id">
                            Paket reseller
                        </Label>
                        <PackageSelect
                            id="reseller_package_id"
                            name="reseller_package_id"
                            packages={packages}
                            defaultValue={account.reseller_package_id}
                            invalid={Boolean(errors.reseller_package_id)}
                        />
                        <InputError message={errors.reseller_package_id} />
                    </div>

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Batal
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={processing}>
                            {processing && <Spinner />}
                            Simpan paket reseller
                        </Button>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}
