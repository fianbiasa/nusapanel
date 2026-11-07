import { Form, Head, Link, usePage, usePoll } from '@inertiajs/react';
import { Plus, Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
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
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime } from '@/lib/format';
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

const statusVariants: Record<
    AccountStatus,
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    pending: 'outline',
    active: 'default',
    suspended: 'secondary',
    deleting: 'outline',
    failed: 'destructive',
};

const busyStatuses: AccountStatus[] = ['pending', 'deleting'];

const deletableStatuses: AccountStatus[] = ['active', 'suspended', 'failed'];

export default function AccountsIndex({ accounts, filters }: Props) {
    const { auth } = usePage().props;
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
            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Accounts"
                        description="Panel accounts and the Linux users that back them on the server."
                    />
                    <Button asChild>
                        <Link href={create()}>
                            <Plus />
                            New account
                        </Link>
                    </Button>
                </div>

                <Form
                    {...index.form()}
                    options={{ preserveState: true, replace: true }}
                    className="flex w-full max-w-md items-center gap-2"
                >
                    <div className="relative flex-1">
                        <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            type="search"
                            name="search"
                            defaultValue={filters.search}
                            placeholder="Search by username, name or email"
                            aria-label="Search accounts"
                            maxLength={100}
                            className="pl-9"
                        />
                    </div>
                    <Button type="submit" variant="outline">
                        Search
                    </Button>
                </Form>

                {accounts.data.length === 0 ? (
                    <p className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                        {filters.search
                            ? `No accounts match "${filters.search}".`
                            : 'No accounts yet. Create one to get started.'}
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                <tr>
                                    <th className="px-4 py-2 font-medium">
                                        Username
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Email
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Role
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Status
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Owner
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Created
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        <span className="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {accounts.data.map((account) => (
                                    <tr key={account.id} className="align-top">
                                        <td className="px-4 py-3">
                                            <code className="font-mono text-xs">
                                                {account.username}
                                            </code>
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                {account.name}
                                            </p>
                                        </td>
                                        <td className="px-4 py-3">
                                            {account.email}
                                        </td>
                                        <td className="px-4 py-3 capitalize">
                                            {account.role}
                                        </td>
                                        <td className="px-4 py-3">
                                            <Badge
                                                variant={
                                                    statusVariants[
                                                        account.status
                                                    ]
                                                }
                                            >
                                                {busyStatuses.includes(
                                                    account.status,
                                                ) && <Spinner />}
                                                {account.status}
                                            </Badge>
                                            {account.status === 'suspended' &&
                                                account.suspend_reason && (
                                                    <p className="mt-1 max-w-xs text-xs text-muted-foreground">
                                                        {account.suspend_reason}
                                                    </p>
                                                )}
                                        </td>
                                        <td className="px-4 py-3">
                                            {account.owner ?? '—'}
                                        </td>
                                        <td className="px-4 py-3 whitespace-nowrap">
                                            {account.created_at
                                                ? formatDateTime(
                                                      account.created_at,
                                                  )
                                                : '—'}
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex justify-end gap-2">
                                                {account.status === 'active' &&
                                                    !isProtected(account) && (
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            onClick={() =>
                                                                openDialog(
                                                                    'suspend',
                                                                    account,
                                                                )
                                                            }
                                                        >
                                                            Suspend
                                                        </Button>
                                                    )}
                                                {account.status ===
                                                    'suspended' && (
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() =>
                                                            openDialog(
                                                                'unsuspend',
                                                                account,
                                                            )
                                                        }
                                                    >
                                                        Unsuspend
                                                    </Button>
                                                )}
                                                {deletableStatuses.includes(
                                                    account.status,
                                                ) &&
                                                    !isProtected(account) && (
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                                            onClick={() =>
                                                                openDialog(
                                                                    'delete',
                                                                    account,
                                                                )
                                                            }
                                                        >
                                                            Delete
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

                <Pagination page={accounts} />
            </div>

            <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                <DialogContent>
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
            title: `Suspend ${username}`,
            description: `${username} will be signed out and blocked from signing in, and the account will be locked on the server.`,
            confirm: 'Suspend account',
            variant: 'destructive' as const,
        },
        unsuspend: {
            form: unsuspend.form(account.id),
            title: `Unsuspend ${username}`,
            description: `${username} will be able to sign in again, and the account will be unlocked on the server.`,
            confirm: 'Unsuspend account',
            variant: 'default' as const,
        },
        delete: {
            form: destroy.form(account.id),
            title: `Delete ${username}`,
            description: `This removes ${username} and its files from the server. This cannot be undone.`,
            confirm: 'Delete account',
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
                            <Label htmlFor="reason">Reason (optional)</Label>
                            <Input
                                id="reason"
                                name="reason"
                                maxLength={255}
                                placeholder="e.g. Unpaid invoice"
                                autoComplete="off"
                            />
                            <InputError message={errors.reason} />
                        </div>
                    )}

                    <InputError message={errors.account} />

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Cancel
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

AccountsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Accounts',
            href: index(),
        },
    ],
};
