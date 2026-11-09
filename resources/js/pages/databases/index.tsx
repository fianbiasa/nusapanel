import { Form, Head, useHttp, usePoll } from '@inertiajs/react';
import {
    Database,
    ExternalLink,
    KeyRound,
    Pencil,
    Plus,
    Trash2,
    UserRound,
    Wand2,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatBytes, formatDateTime } from '@/lib/format';
import {
    destroy as destroyUser,
    store as storeUser,
    update as updateUser,
} from '@/routes/database-users';
import { destroy, phpmyadmin, store } from '@/routes/databases';
import type { AccountLimits } from '@/types';

type Status = 'pending' | 'active' | 'failed' | 'deleting';

type DatabaseRow = {
    id: number;
    name: string;
    status: Status;
    size_bytes: number | null;
    measured_at: string | null;
    users: string[];
    created_at: string | null;
};

type DatabaseUserRow = {
    id: number;
    name: string;
    status: Status;
    database_ids: number[];
    created_at: string | null;
};

type Props = {
    databases: DatabaseRow[];
    database_users: DatabaseUserRow[];
    prefix: string;
    max_lengths: { database: number; user: number };
    limits: AccountLimits;
    connection: { host: string; port: number };
    phpmyadmin: { available: boolean; session_minutes: number };
};

type Action =
    | { type: 'delete-database'; database: DatabaseRow }
    | { type: 'add-user' }
    | { type: 'edit-user'; user: DatabaseUserRow }
    | { type: 'delete-user'; user: DatabaseUserRow };

const busyStatuses: Status[] = ['pending', 'deleting'];

/** A random password of 20 letters and digits, without look-alikes. */
function generatePassword(): string {
    const alphabet =
        'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    const values = crypto.getRandomValues(new Uint32Array(20));

    return Array.from(
        values,
        (value) => alphabet[value % alphabet.length],
    ).join('');
}

export default function DatabasesIndex({
    databases,
    database_users,
    prefix,
    max_lengths,
    limits,
    connection,
    phpmyadmin: phpMyAdmin,
}: Props) {
    const [action, setAction] = useState<Action | null>(null);
    const [dialogOpen, setDialogOpen] = useState(false);

    const openDialog = (next: Action) => {
        setAction(next);
        setDialogOpen(true);
    };

    const closeDialog = () => setDialogOpen(false);

    // Refresh the lists while the agent is still creating, changing or
    // dropping databases or users.
    const hasBusyRecords = [...databases, ...database_users].some((row) =>
        busyStatuses.includes(row.status),
    );
    const { start, stop } = usePoll(
        3000,
        { only: ['databases', 'database_users', 'limits'] },
        { autoStart: false },
    );

    useEffect(() => {
        if (hasBusyRecords) {
            start();
        } else {
            stop();
        }
    }, [hasBusyRecords, start, stop]);

    const limit = limits.quotas.databases.limit;
    const used = limits.quotas.databases.used ?? databases.length;
    const isAtLimit = limit !== null && used >= limit;
    const usableDatabases = databases.filter(
        (database) => database.status !== 'deleting',
    );
    const databaseNames = new Map(
        databases.map((database) => [database.id, database.name]),
    );

    return (
        <>
            <Head title="Databases" />

            <PageHeader
                title="Databases"
                description={`Database MariaDB untuk website kamu. Nama database dan user selalu diawali ${prefix}.`}
                actions={
                    phpMyAdmin.available && (
                        <PhpMyAdminButton
                            hasDatabases={databases.some(
                                (database) => database.status === 'active',
                            )}
                            sessionMinutes={phpMyAdmin.session_minutes}
                        />
                    )
                }
            />

            <Panel
                title="Buat database"
                description="Untuk WordPress dan aplikasi lain: buat database sekaligus user-nya."
                action={
                    limit !== null && (
                        <span className="shrink-0 rounded-full bg-muted px-3 py-1 text-xs font-semibold whitespace-nowrap text-muted-foreground">
                            {used} dari {limit} database
                        </span>
                    )
                }
            >
                <CreateDatabaseForm
                    prefix={prefix}
                    maxLengths={max_lengths}
                    disabled={isAtLimit}
                />
                {isAtLimit && (
                    <p className="mt-4 text-sm text-muted-foreground">
                        {limit === 0
                            ? 'Paket kamu tidak termasuk database.'
                            : `Paket kamu sudah mencapai batas ${limit} database.`}{' '}
                        Hapus database yang tidak dipakai, atau minta paket yang
                        lebih besar ke penyedia hosting kamu.
                    </p>
                )}
            </Panel>

            <Panel
                title="Daftar database"
                description={`${databases.length} database · host ${connection.host}, port ${connection.port}`}
                flush
            >
                {databases.length === 0 ? (
                    <EmptyState icon={<Database className="size-6" />}>
                        Belum ada database.
                    </EmptyState>
                ) : (
                    <div className="overflow-x-auto">
                        <table className={tableStyles.table}>
                            <thead className={tableStyles.head}>
                                <tr>
                                    <th className={tableStyles.th}>Database</th>
                                    <th className={tableStyles.th}>Status</th>
                                    <th className={tableStyles.th}>Ukuran</th>
                                    <th className={tableStyles.th}>User</th>
                                    <th className={tableStyles.th}>
                                        <span className="sr-only">Aksi</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {databases.map((database) => (
                                    <tr
                                        key={database.id}
                                        className={tableStyles.row}
                                    >
                                        <td className={tableStyles.td}>
                                            <span className="font-mono font-semibold break-all">
                                                {database.name}
                                            </span>
                                        </td>
                                        <td className={tableStyles.td}>
                                            <StatusBadge
                                                status={database.status}
                                            />
                                        </td>
                                        <td
                                            className={`${tableStyles.td} whitespace-nowrap text-muted-foreground`}
                                            title={
                                                database.measured_at
                                                    ? `Diukur ${formatDateTime(database.measured_at)}`
                                                    : undefined
                                            }
                                        >
                                            {database.size_bytes === null
                                                ? '—'
                                                : formatBytes(
                                                      database.size_bytes,
                                                  )}
                                        </td>
                                        <td className={tableStyles.td}>
                                            {database.users.length === 0 ? (
                                                <span className="text-muted-foreground">
                                                    Belum ada
                                                </span>
                                            ) : (
                                                <div className="flex flex-wrap gap-1">
                                                    {database.users.map(
                                                        (user) => (
                                                            <span
                                                                key={user}
                                                                className="rounded-md bg-muted px-1.5 py-0.5 font-mono text-xs"
                                                            >
                                                                {user}
                                                            </span>
                                                        ),
                                                    )}
                                                </div>
                                            )}
                                        </td>
                                        <td className={tableStyles.td}>
                                            <div className="flex justify-end">
                                                {!busyStatuses.includes(
                                                    database.status,
                                                ) && (
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        aria-label={`Hapus database ${database.name}`}
                                                        onClick={() =>
                                                            openDialog({
                                                                type: 'delete-database',
                                                                database,
                                                            })
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
            </Panel>

            <Panel
                title="User database"
                description="Akun yang dipakai aplikasi untuk masuk ke database. Hanya bisa terhubung dari server ini."
                action={
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => openDialog({ type: 'add-user' })}
                    >
                        <Plus /> Tambah user
                    </Button>
                }
                flush
            >
                {database_users.length === 0 ? (
                    <EmptyState icon={<UserRound className="size-6" />}>
                        Belum ada user database.
                    </EmptyState>
                ) : (
                    <div className="overflow-x-auto">
                        <table className={tableStyles.table}>
                            <thead className={tableStyles.head}>
                                <tr>
                                    <th className={tableStyles.th}>User</th>
                                    <th className={tableStyles.th}>Status</th>
                                    <th className={tableStyles.th}>Akses ke</th>
                                    <th className={tableStyles.th}>
                                        <span className="sr-only">Aksi</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {database_users.map((user) => (
                                    <tr
                                        key={user.id}
                                        className={tableStyles.row}
                                    >
                                        <td className={tableStyles.td}>
                                            <span className="font-mono font-semibold break-all">
                                                {user.name}
                                            </span>
                                        </td>
                                        <td className={tableStyles.td}>
                                            <StatusBadge status={user.status} />
                                        </td>
                                        <td
                                            className={`${tableStyles.td} font-mono text-xs text-muted-foreground`}
                                        >
                                            {user.database_ids.length === 0
                                                ? 'Belum ada'
                                                : user.database_ids
                                                      .map((id) =>
                                                          databaseNames.get(id),
                                                      )
                                                      .filter(Boolean)
                                                      .join(', ')}
                                        </td>
                                        <td className={tableStyles.td}>
                                            {!busyStatuses.includes(
                                                user.status,
                                            ) && (
                                                <div className="flex justify-end gap-1">
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        aria-label={`Ubah user ${user.name}`}
                                                        onClick={() =>
                                                            openDialog({
                                                                type: 'edit-user',
                                                                user,
                                                            })
                                                        }
                                                    >
                                                        <Pencil />
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        aria-label={`Hapus user ${user.name}`}
                                                        onClick={() =>
                                                            openDialog({
                                                                type: 'delete-user',
                                                                user,
                                                            })
                                                        }
                                                    >
                                                        <Trash2 />
                                                    </Button>
                                                </div>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Panel>

            <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                <DialogContent className="rounded-2xl">
                    {action?.type === 'delete-database' && (
                        <ConfirmForm
                            key={`database-${action.database.id}`}
                            form={destroy.form(action.database.id)}
                            title={`Hapus database ${action.database.name}?`}
                            description="Semua tabel dan isinya ikut terhapus dan tidak bisa dikembalikan. User database-nya tetap ada."
                            confirmLabel="Hapus database"
                            errorKey="database"
                            onSuccess={closeDialog}
                        />
                    )}
                    {action?.type === 'delete-user' && (
                        <ConfirmForm
                            key={`user-${action.user.id}`}
                            form={destroyUser.form(action.user.id)}
                            title={`Hapus user ${action.user.name}?`}
                            description="Aplikasi yang memakai user ini tidak bisa masuk ke database lagi. Database-nya tetap ada."
                            confirmLabel="Hapus user"
                            errorKey="user"
                            onSuccess={closeDialog}
                        />
                    )}
                    {action?.type === 'add-user' && (
                        <UserForm
                            key="add-user"
                            prefix={prefix}
                            maxLength={max_lengths.user}
                            databases={usableDatabases}
                            onSuccess={closeDialog}
                        />
                    )}
                    {action?.type === 'edit-user' && (
                        <UserForm
                            key={`edit-user-${action.user.id}`}
                            prefix={prefix}
                            maxLength={max_lengths.user}
                            databases={usableDatabases}
                            user={action.user}
                            onSuccess={closeDialog}
                        />
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}

type PhpMyAdminTicket = { url: string; ticket: string };

/**
 * Opens phpMyAdmin in a new tab, signed in to all of the account's
 * databases. Browsers only allow opening a tab during the click, so the tab
 * opens first; once the panel returns a ticket, the tab posts it to
 * phpMyAdmin itself, after its link back to the panel is cut.
 */
function PhpMyAdminButton({
    hasDatabases,
    sessionMinutes,
}: {
    hasDatabases: boolean;
    sessionMinutes: number;
}) {
    const http = useHttp<Record<string, never>, PhpMyAdminTicket>({});

    const open = async () => {
        const tab = window.open('', '_blank');

        if (tab) {
            tab.document.title = 'phpMyAdmin';
            tab.document.body.textContent = 'Membuka phpMyAdmin…';
        }

        let response: PhpMyAdminTicket | undefined;

        try {
            response = await http.post(phpmyadmin.url(), {
                onError: (errors) => {
                    const message = Object.values(errors)[0];

                    if (message) {
                        toast.error(message);
                    }
                },
            });
        } catch {
            toast.error(
                'phpMyAdmin tidak bisa dibuka. Coba lagi sebentar lagi.',
            );
        }

        if (!response) {
            tab?.close();

            return;
        }

        // Without a tab (pop-ups blocked), phpMyAdmin opens here instead.
        const target = tab ?? window;
        const form = target.document.createElement('form');
        const ticket = target.document.createElement('input');

        form.method = 'post';
        form.action = response.url;
        ticket.type = 'hidden';
        ticket.name = 'ticket';
        ticket.value = response.ticket;
        form.append(ticket);
        target.document.body.append(form);

        if (tab) {
            tab.opener = null;
        }

        form.submit();
    };

    return (
        <Button
            variant="outline"
            size="lg"
            disabled={!hasDatabases || http.processing}
            onClick={open}
            title={
                hasDatabases
                    ? `Masuk otomatis ke semua database kamu; berlaku ${sessionMinutes} menit`
                    : 'Buat database dulu'
            }
        >
            {http.processing ? <Spinner /> : <ExternalLink />}
            Buka phpMyAdmin
        </Button>
    );
}

function EmptyState({
    icon,
    children,
}: {
    icon: ReactNode;
    children: ReactNode;
}) {
    return (
        <div className="flex flex-col items-center gap-3 px-6 pt-4 pb-10 text-center">
            <span className="flex size-14 items-center justify-center rounded-full bg-accent text-accent-foreground">
                {icon}
            </span>
            <p className="max-w-sm text-sm text-muted-foreground">{children}</p>
        </div>
    );
}

/** A name input with the account's "<username>_" prefix in front of it. */
function PrefixedNameInput({
    id,
    name,
    prefix,
    maxLength,
    value,
    onChange,
    placeholder,
    disabled = false,
    invalid,
}: {
    id: string;
    name: string;
    prefix: string;
    maxLength: number;
    value: string;
    onChange: (value: string) => void;
    placeholder: string;
    disabled?: boolean;
    invalid: boolean;
}) {
    return (
        <div className="flex h-11 items-stretch overflow-hidden rounded-xl border border-input bg-card shadow-xs transition-[color,box-shadow] focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50 has-aria-invalid:border-destructive">
            <span className="flex items-center border-r bg-muted px-3 font-mono text-sm text-muted-foreground select-none">
                {prefix}
            </span>
            <input
                id={id}
                name={name}
                value={value}
                onChange={(event) => onChange(event.target.value.toLowerCase())}
                required
                disabled={disabled}
                autoComplete="off"
                autoCapitalize="none"
                spellCheck={false}
                maxLength={maxLength}
                placeholder={placeholder}
                aria-invalid={invalid || undefined}
                className="min-w-0 flex-1 bg-transparent px-3 font-mono text-sm outline-none placeholder:text-muted-foreground disabled:cursor-not-allowed disabled:opacity-50"
            />
        </div>
    );
}

/**
 * A password input with a generator. A generated password is shown so it
 * can be copied into the application's settings.
 */
function PasswordWithGenerator({
    id,
    value,
    onChange,
    required,
    placeholder,
    disabled = false,
    invalid,
}: {
    id: string;
    value: string;
    onChange: (value: string) => void;
    required: boolean;
    placeholder: string;
    disabled?: boolean;
    invalid: boolean;
}) {
    const [isVisible, setIsVisible] = useState(false);

    return (
        <div className="flex gap-2">
            <Input
                id={id}
                name="password"
                type={isVisible ? 'text' : 'password'}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                required={required}
                disabled={disabled}
                minLength={12}
                maxLength={128}
                autoComplete="new-password"
                placeholder={placeholder}
                className="font-mono"
                aria-invalid={invalid || undefined}
            />
            <Button
                type="button"
                variant="outline"
                className="h-11 shrink-0"
                disabled={disabled}
                onClick={() => {
                    onChange(generatePassword());
                    setIsVisible(true);
                }}
            >
                <Wand2 /> Buat
            </Button>
        </div>
    );
}

function CreateDatabaseForm({
    prefix,
    maxLengths,
    disabled,
}: {
    prefix: string;
    maxLengths: { database: number; user: number };
    disabled: boolean;
}) {
    const [name, setName] = useState('');
    const [withUser, setWithUser] = useState(true);
    const [userName, setUserName] = useState('');
    const [isUserNameEdited, setIsUserNameEdited] = useState(false);
    const [password, setPassword] = useState('');

    const reset = () => {
        setName('');
        setUserName('');
        setIsUserNameEdited(false);
        setPassword('');
    };

    return (
        <Form
            {...store.form()}
            options={{ preserveScroll: true }}
            onSuccess={reset}
            className="grid max-w-2xl gap-5"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="database-name">Nama database</Label>
                        <PrefixedNameInput
                            id="database-name"
                            name="name"
                            prefix={prefix}
                            maxLength={maxLengths.database}
                            value={name}
                            onChange={(value) => {
                                setName(value);

                                // Suggest the same name for the new user
                                // until it is typed in by hand.
                                if (!isUserNameEdited) {
                                    setUserName(
                                        value.slice(0, maxLengths.user),
                                    );
                                }
                            }}
                            placeholder="wordpress"
                            disabled={disabled}
                            invalid={Boolean(errors.name)}
                        />
                        <InputError message={errors.name} />
                    </div>

                    <input
                        type="hidden"
                        name="create_user"
                        value={withUser ? '1' : '0'}
                    />
                    <div className="flex items-center gap-3">
                        <Checkbox
                            id="create-user"
                            checked={withUser}
                            disabled={disabled}
                            onCheckedChange={(checked) =>
                                setWithUser(checked === true)
                            }
                        />
                        <Label htmlFor="create-user">
                            Buat juga user baru dengan akses ke database ini
                        </Label>
                    </div>

                    {withUser && (
                        <div className="grid gap-5 sm:grid-cols-2">
                            <div className="grid content-start gap-2">
                                <Label htmlFor="database-user-name">
                                    Nama user
                                </Label>
                                <PrefixedNameInput
                                    id="database-user-name"
                                    name="user_name"
                                    prefix={prefix}
                                    maxLength={maxLengths.user}
                                    value={userName}
                                    onChange={(value) => {
                                        setUserName(value);
                                        setIsUserNameEdited(true);
                                    }}
                                    placeholder="wordpress"
                                    disabled={disabled}
                                    invalid={Boolean(errors.user_name)}
                                />
                                <InputError message={errors.user_name} />
                            </div>
                            <div className="grid content-start gap-2">
                                <Label htmlFor="database-user-password">
                                    Password
                                </Label>
                                <PasswordWithGenerator
                                    id="database-user-password"
                                    value={password}
                                    onChange={setPassword}
                                    required
                                    placeholder="Minimal 12 karakter"
                                    disabled={disabled}
                                    invalid={Boolean(errors.password)}
                                />
                                <InputError message={errors.password} />
                            </div>
                            <p className="text-xs text-muted-foreground sm:col-span-2">
                                Simpan password ini untuk pengaturan aplikasi
                                kamu (misalnya wp-config.php). Panel tidak bisa
                                menampilkannya lagi, tapi kamu bisa menggantinya
                                kapan saja.
                            </p>
                        </div>
                    )}

                    <div>
                        <Button
                            type="submit"
                            size="lg"
                            disabled={processing || disabled}
                        >
                            {processing ? <Spinner /> : <Database />}
                            Buat database
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function UserForm({
    prefix,
    maxLength,
    databases,
    user,
    onSuccess,
}: {
    prefix: string;
    maxLength: number;
    databases: DatabaseRow[];
    user?: DatabaseUserRow;
    onSuccess: () => void;
}) {
    const [name, setName] = useState('');
    const [password, setPassword] = useState('');
    const [selectedIds, setSelectedIds] = useState<number[]>(
        user?.database_ids ?? [],
    );

    const toggleDatabase = (id: number, isChecked: boolean) =>
        setSelectedIds((current) =>
            isChecked
                ? [...current, id]
                : current.filter((selectedId) => selectedId !== id),
        );

    const form = user ? updateUser.form(user.id) : storeUser.form();

    return (
        <Form
            {...form}
            options={{ preserveScroll: true }}
            onSuccess={onSuccess}
            className="grid gap-5"
        >
            {({ processing, errors }) => (
                <>
                    <DialogHeader>
                        <DialogTitle>
                            {user
                                ? `Ubah user ${user.name}`
                                : 'Tambah user database'}
                        </DialogTitle>
                        <DialogDescription>
                            {user
                                ? 'Kosongkan password untuk tetap memakai yang sekarang.'
                                : 'User hanya bisa terhubung dari server ini (localhost).'}
                        </DialogDescription>
                    </DialogHeader>

                    {!user && (
                        <div className="grid gap-2">
                            <Label htmlFor="new-user-name">Nama user</Label>
                            <PrefixedNameInput
                                id="new-user-name"
                                name="name"
                                prefix={prefix}
                                maxLength={maxLength}
                                value={name}
                                onChange={setName}
                                placeholder="app"
                                invalid={Boolean(errors.name)}
                            />
                            <InputError message={errors.name} />
                        </div>
                    )}

                    <div className="grid gap-2">
                        <Label htmlFor="user-password">
                            {user ? 'Password baru' : 'Password'}
                        </Label>
                        <PasswordWithGenerator
                            id="user-password"
                            value={password}
                            onChange={setPassword}
                            required={!user}
                            placeholder={
                                user
                                    ? 'Tetap memakai password sekarang'
                                    : 'Minimal 12 karakter'
                            }
                            invalid={Boolean(errors.password)}
                        />
                        <InputError message={errors.password} />
                    </div>

                    <fieldset className="grid gap-3">
                        <legend className="mb-2 text-sm font-medium">
                            Akses ke database
                        </legend>
                        {databases.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Belum ada database.
                            </p>
                        ) : (
                            databases.map((database) => (
                                <div
                                    key={database.id}
                                    className="flex items-center gap-3"
                                >
                                    <Checkbox
                                        id={`access-${database.id}`}
                                        checked={selectedIds.includes(
                                            database.id,
                                        )}
                                        onCheckedChange={(checked) =>
                                            toggleDatabase(
                                                database.id,
                                                checked === true,
                                            )
                                        }
                                    />
                                    <Label
                                        htmlFor={`access-${database.id}`}
                                        className="font-mono font-normal break-all"
                                    >
                                        {database.name}
                                    </Label>
                                </div>
                            ))
                        )}
                        {selectedIds.map((id) => (
                            <input
                                key={id}
                                type="hidden"
                                name="databases[]"
                                value={id}
                            />
                        ))}
                        <InputError message={errors.databases} />
                    </fieldset>

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Batal
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={processing}>
                            {processing ? <Spinner /> : <KeyRound />}
                            {user ? 'Simpan' : 'Tambah user'}
                        </Button>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}

function ConfirmForm({
    form,
    title,
    description,
    confirmLabel,
    errorKey,
    onSuccess,
}: {
    form: ReturnType<typeof destroy.form>;
    title: string;
    description: string;
    confirmLabel: string;
    errorKey: string;
    onSuccess: () => void;
}) {
    return (
        <Form
            {...form}
            options={{ preserveScroll: true }}
            onSuccess={onSuccess}
            className="grid gap-4"
        >
            {({ processing, errors }) => (
                <>
                    <DialogHeader>
                        <DialogTitle>{title}</DialogTitle>
                        <DialogDescription>{description}</DialogDescription>
                    </DialogHeader>
                    <InputError message={errors[errorKey]} />
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Batal
                            </Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={processing}
                        >
                            {processing && <Spinner />}
                            {confirmLabel}
                        </Button>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}
