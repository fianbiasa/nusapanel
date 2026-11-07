import { Form, Head, Link, usePage, usePoll } from '@inertiajs/react';
import {
    MoreHorizontal,
    Plus,
    Search,
    ShieldCheck,
    ShieldOff,
    Trash2,
    Users,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
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
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useInitials } from '@/hooks/use-initials';
import { formatDateTime, roleLabels } from '@/lib/format';
import {
    create,
    destroy,
    index,
    suspend,
    unsuspend,
} from '@/routes/admin/accounts';
import type { AccountStatus, Paginated, Role } from '@/types';

type AccountRow = {
    id: number;
    username: string;
    name: string;
    email: string;
    role: Role;
    status: AccountStatus;
    suspend_reason: string | null;
    owner: string | null;
    domain: string | null;
    created_at: string | null;
};

type AccountAction = {
    type: 'suspend' | 'unsuspend' | 'delete';
    account: AccountRow;
};

type Props = {
    accounts: Paginated<AccountRow>;
    filters: { search: string };
};

const busyStatuses: AccountStatus[] = ['pending', 'deleting'];

const deletableStatuses: AccountStatus[] = ['active', 'suspended', 'failed'];

export default function AccountsIndex({ accounts, filters }: Props) {
    const { auth } = usePage().props;
    const getInitials = useInitials();
    const [action, setAction] = useState<AccountAction | null>(null);
    const [dialogOpen, setDialogOpen] = useState(false);

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

    const openDialog = (type: AccountAction['type'], account: AccountRow) => {
        setAction({ type, account });
        setDialogOpen(true);
    };

    const isProtected = (account: AccountRow) =>
        account.role === 'admin' || account.id === auth.user.id;

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
                        className="hidden w-full max-w-sm items-center gap-2 md:flex"
                    >
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
                                : 'Belum ada akun. Buat akun pertama untuk memulai.'}
                        </p>
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className={tableStyles.table}>
                            <thead className={tableStyles.head}>
                                <tr>
                                    <th className={tableStyles.th}>Akun</th>
                                    <th className={tableStyles.th}>Domain</th>
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
                                            <div className="flex items-center gap-3">
                                                <span className="flex size-9 shrink-0 items-center justify-center rounded-full nusa-brand-gradient text-xs font-semibold text-white">
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
                    {action && (
                        <AccountActionForm
                            key={`${action.type}-${action.account.id}`}
                            action={action}
                            onSuccess={() => setDialogOpen(false)}
                        />
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}

function AccountActionForm({
    action,
    onSuccess,
}: {
    action: AccountAction;
    onSuccess: () => void;
}) {
    const { type, account } = action;
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
    onAction: (type: AccountAction['type'], account: AccountRow) => void;
}) {
    const canSuspend = account.status === 'active' && !isProtected;
    const canUnsuspend = account.status === 'suspended';
    const canDelete =
        deletableStatuses.includes(account.status) && !isProtected;

    if (!canSuspend && !canUnsuspend && !canDelete) {
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
