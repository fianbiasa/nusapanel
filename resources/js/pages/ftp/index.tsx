import { Form, Head, usePoll } from '@inertiajs/react';
import { FolderUp, KeyRound, Pencil, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { AffixInput } from '@/components/affix-input';
import { GeneratedPasswordInput } from '@/components/generated-password-input';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
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
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime, formatMegabytes } from '@/lib/format';
import { destroy, store, update } from '@/routes/ftp';
import {
    destroy as removeSftpPassword,
    update as setSftpPassword,
} from '@/routes/sftp-password';
import type { AccountLimits } from '@/types';

type Status = 'pending' | 'active' | 'failed';

type FtpAccountRow = {
    id: number;
    login: string;
    directory: string;
    quota_mb: number | null;
    status: Status;
    created_at: string | null;
};

type Domain = { id: number; name: string };

type Props = {
    ftp_accounts: FtpAccountRow[];
    domains: Domain[];
    home: string;
    max_directory: number;
    max_quota_mb: number;
    limits: AccountLimits;
    connection: { host: string; port: number };
    sftp: SftpState;
};

type SftpState = {
    username: string;
    port: number;
    password_set_at: string | null;
    pending: boolean;
    error: string | null;
};

type Action =
    | { type: 'edit'; account: FtpAccountRow }
    | { type: 'delete'; account: FtpAccountRow };

/** The folder of a website, which most FTP logins are for. */
function websiteFolder(domain: string): string {
    return `domains/${domain}/public_html`;
}

export default function FtpIndex({
    ftp_accounts,
    domains,
    home,
    max_directory,
    max_quota_mb,
    limits,
    connection,
    sftp,
}: Props) {
    const [action, setAction] = useState<Action | null>(null);
    const [dialogOpen, setDialogOpen] = useState(false);

    const openDialog = (next: Action) => {
        setAction(next);
        setDialogOpen(true);
    };

    const closeDialog = () => setDialogOpen(false);

    // Refresh while the agent is still preparing folders or setting the
    // SFTP password.
    const hasPendingLogins =
        sftp.pending ||
        ftp_accounts.some((account) => account.status === 'pending');
    const { start, stop } = usePoll(
        3000,
        { only: ['ftp_accounts', 'limits', 'sftp'] },
        { autoStart: false },
    );

    useEffect(() => {
        if (hasPendingLogins) {
            start();
        } else {
            stop();
        }
    }, [hasPendingLogins, start, stop]);

    const limit = limits.quotas.ftp_accounts.limit;
    const used = limits.quotas.ftp_accounts.used ?? ftp_accounts.length;
    const isAtLimit = limit !== null && used >= limit;

    return (
        <>
            <Head title="FTP & SFTP" />

            <PageHeader
                title="FTP & SFTP"
                description="Akun FTP untuk mengunggah file ke website, yang masing-masing hanya bisa membuka foldernya sendiri, dan SFTP untuk akun utama kamu."
            />

            <Panel
                title="Buat akun FTP"
                description="Misalnya untuk developer yang mengurus satu website saja."
                action={
                    limit !== null && (
                        <span className="shrink-0 rounded-full bg-muted px-3 py-1 text-xs font-semibold whitespace-nowrap text-muted-foreground">
                            {used} dari {limit} akun FTP
                        </span>
                    )
                }
            >
                {domains.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Belum ada domain. Tambahkan domain di halaman Domains
                        dulu; nama akun FTP memakai domain kamu.
                    </p>
                ) : (
                    <CreateFtpAccountForm
                        domains={domains}
                        home={home}
                        maxDirectory={max_directory}
                        maxQuotaMb={max_quota_mb}
                        disabled={isAtLimit}
                    />
                )}
                {isAtLimit && (
                    <p className="mt-4 text-sm text-muted-foreground">
                        {limit === 0
                            ? 'Paket kamu tidak termasuk akun FTP.'
                            : `Paket kamu sudah mencapai batas ${limit} akun FTP.`}{' '}
                        Hapus akun yang tidak dipakai, atau minta paket yang
                        lebih besar ke penyedia hosting kamu.
                    </p>
                )}
            </Panel>

            <Panel
                title="Daftar akun FTP"
                description={`${ftp_accounts.length} akun FTP`}
                flush
            >
                {ftp_accounts.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 px-6 pt-4 pb-10 text-center">
                        <span className="flex size-14 items-center justify-center rounded-full bg-accent text-accent-foreground">
                            <FolderUp className="size-6" />
                        </span>
                        <p className="max-w-sm text-sm text-muted-foreground">
                            Belum ada akun FTP.
                        </p>
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className={tableStyles.table}>
                            <thead className={tableStyles.head}>
                                <tr>
                                    <th className={tableStyles.th}>Login</th>
                                    <th className={tableStyles.th}>Folder</th>
                                    <th className={tableStyles.th}>Kuota</th>
                                    <th className={tableStyles.th}>Status</th>
                                    <th className={tableStyles.th}>
                                        <span className="sr-only">Aksi</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {ftp_accounts.map((account) => (
                                    <tr
                                        key={account.id}
                                        className={tableStyles.row}
                                    >
                                        <td className={tableStyles.td}>
                                            <span className="font-mono font-semibold break-all">
                                                {account.login}
                                            </span>
                                        </td>
                                        <td
                                            className={`${tableStyles.td} font-mono text-xs break-all text-muted-foreground`}
                                        >
                                            {account.directory === ''
                                                ? home
                                                : `${home}/${account.directory}`}
                                        </td>
                                        <td
                                            className={`${tableStyles.td} whitespace-nowrap text-muted-foreground`}
                                        >
                                            {account.quota_mb === null
                                                ? 'Tanpa batas'
                                                : formatMegabytes(
                                                      account.quota_mb,
                                                  )}
                                        </td>
                                        <td className={tableStyles.td}>
                                            <StatusBadge
                                                status={account.status}
                                            />
                                        </td>
                                        <td className={tableStyles.td}>
                                            {account.status !== 'pending' && (
                                                <div className="flex justify-end gap-1">
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        aria-label={`Ubah akun FTP ${account.login}`}
                                                        onClick={() =>
                                                            openDialog({
                                                                type: 'edit',
                                                                account,
                                                            })
                                                        }
                                                    >
                                                        <Pencil />
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        aria-label={`Hapus akun FTP ${account.login}`}
                                                        onClick={() =>
                                                            openDialog({
                                                                type: 'delete',
                                                                account,
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

            <Panel
                title="Cara terhubung"
                description="Pakai program FTP seperti FileZilla atau WinSCP."
            >
                <dl className="grid gap-x-8 gap-y-3 text-sm sm:grid-cols-[auto_1fr]">
                    <dt className="text-muted-foreground">Host</dt>
                    <dd className="font-mono font-semibold break-all">
                        {connection.host}
                    </dd>
                    <dt className="text-muted-foreground">Port</dt>
                    <dd className="font-mono font-semibold">
                        {connection.port}
                    </dd>
                    <dt className="text-muted-foreground">Enkripsi</dt>
                    <dd>
                        Wajib: FTP lewat TLS eksplisit (FTPES). Di FileZilla,
                        pilih &ldquo;Require explicit FTP over TLS&rdquo;.
                    </dd>
                    <dt className="text-muted-foreground">Login</dt>
                    <dd>
                        Nama akun FTP lengkap, misalnya{' '}
                        <span className="font-mono">
                            nama@{domains[0]?.name ?? 'domainkamu.com'}
                        </span>
                        , dan password-nya.
                    </dd>
                </dl>
            </Panel>

            <SftpPanel sftp={sftp} host={connection.host} />

            <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                <DialogContent className="rounded-2xl">
                    {action?.type === 'edit' && (
                        <EditFtpAccountForm
                            key={`edit-${action.account.id}`}
                            account={action.account}
                            home={home}
                            maxDirectory={max_directory}
                            maxQuotaMb={max_quota_mb}
                            onSuccess={closeDialog}
                        />
                    )}
                    {action?.type === 'delete' && (
                        <Form
                            key={`delete-${action.account.id}`}
                            {...destroy.form(action.account.id)}
                            options={{ preserveScroll: true }}
                            onSuccess={closeDialog}
                            className="grid gap-4"
                        >
                            {({ processing }) => (
                                <>
                                    <DialogHeader>
                                        <DialogTitle>
                                            Hapus akun FTP{' '}
                                            {action.account.login}?
                                        </DialogTitle>
                                        <DialogDescription>
                                            Akun ini tidak bisa login lagi.
                                            Folder dan file-nya tetap ada.
                                        </DialogDescription>
                                    </DialogHeader>
                                    <DialogFooter className="gap-2">
                                        <DialogClose asChild>
                                            <Button
                                                type="button"
                                                variant="secondary"
                                            >
                                                Batal
                                            </Button>
                                        </DialogClose>
                                        <Button
                                            type="submit"
                                            variant="destructive"
                                            disabled={processing}
                                        >
                                            {processing && <Spinner />}
                                            Hapus akun FTP
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}

function CreateFtpAccountForm({
    domains,
    home,
    maxDirectory,
    maxQuotaMb,
    disabled,
}: {
    domains: Domain[];
    home: string;
    maxDirectory: number;
    maxQuotaMb: number;
    disabled: boolean;
}) {
    const [name, setName] = useState('');
    const [domainId, setDomainId] = useState(String(domains[0].id));
    const [directory, setDirectory] = useState(websiteFolder(domains[0].name));
    const [isDirectoryEdited, setIsDirectoryEdited] = useState(false);
    const [password, setPassword] = useState('');
    const [quota, setQuota] = useState('');

    const chooseDomain = (id: string) => {
        setDomainId(id);

        // The folder follows the domain until it is typed in by hand.
        const domain = domains.find((option) => String(option.id) === id);

        if (domain && !isDirectoryEdited) {
            setDirectory(websiteFolder(domain.name));
        }
    };

    const reset = () => {
        setName('');
        setPassword('');
        setQuota('');
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
                        <Label htmlFor="ftp-name">Nama akun</Label>
                        <input
                            type="hidden"
                            name="domain_id"
                            value={domainId}
                        />
                        <div className="flex flex-col gap-2 sm:flex-row">
                            <div className="min-w-0 flex-1">
                                <AffixInput
                                    id="ftp-name"
                                    name="name"
                                    suffix="@"
                                    value={name}
                                    onChange={(event) =>
                                        setName(
                                            event.target.value.toLowerCase(),
                                        )
                                    }
                                    required
                                    disabled={disabled}
                                    maxLength={32}
                                    placeholder="developer"
                                    invalid={Boolean(errors.name)}
                                />
                            </div>
                            <Select
                                value={domainId}
                                onValueChange={chooseDomain}
                                disabled={disabled}
                            >
                                <SelectTrigger
                                    aria-label="Domain"
                                    aria-invalid={
                                        errors.domain_id ? true : undefined
                                    }
                                    className="w-full rounded-xl bg-card px-3.5 font-mono data-[size=default]:h-11 sm:w-auto sm:min-w-56"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent className="rounded-xl">
                                    {domains.map((domain) => (
                                        <SelectItem
                                            key={domain.id}
                                            value={String(domain.id)}
                                        >
                                            {domain.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <InputError message={errors.name ?? errors.domain_id} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="ftp-directory">Folder</Label>
                        <AffixInput
                            id="ftp-directory"
                            name="directory"
                            prefix={`${home}/`}
                            value={directory}
                            onChange={(event) => {
                                setDirectory(event.target.value);
                                setIsDirectoryEdited(true);
                            }}
                            disabled={disabled}
                            maxLength={maxDirectory}
                            placeholder="kosongkan untuk seluruh home"
                            invalid={Boolean(errors.directory)}
                        />
                        <p className="text-xs text-muted-foreground">
                            Akun ini hanya bisa melihat folder ini dan isinya.
                            Kosongkan untuk seluruh folder home.
                        </p>
                        <InputError message={errors.directory} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="ftp-password">Password</Label>
                        <GeneratedPasswordInput
                            id="ftp-password"
                            value={password}
                            onChange={setPassword}
                            required
                            placeholder="Minimal 12 karakter"
                            disabled={disabled}
                            invalid={Boolean(errors.password)}
                        />
                        <InputError message={errors.password} />
                    </div>

                    <QuotaField
                        id="ftp-quota"
                        value={quota}
                        onChange={setQuota}
                        maxQuotaMb={maxQuotaMb}
                        disabled={disabled}
                        error={errors.quota_mb}
                    />

                    <div>
                        <Button
                            type="submit"
                            size="lg"
                            disabled={processing || disabled}
                        >
                            {processing ? <Spinner /> : <FolderUp />}
                            Buat akun FTP
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function EditFtpAccountForm({
    account,
    home,
    maxDirectory,
    maxQuotaMb,
    onSuccess,
}: {
    account: FtpAccountRow;
    home: string;
    maxDirectory: number;
    maxQuotaMb: number;
    onSuccess: () => void;
}) {
    const [directory, setDirectory] = useState(account.directory);
    const [password, setPassword] = useState('');
    const [quota, setQuota] = useState(
        account.quota_mb === null ? '' : String(account.quota_mb),
    );

    return (
        <Form
            {...update.form(account.id)}
            options={{ preserveScroll: true }}
            onSuccess={onSuccess}
            className="grid gap-5"
        >
            {({ processing, errors }) => (
                <>
                    <DialogHeader>
                        <DialogTitle>Ubah akun FTP {account.login}</DialogTitle>
                        <DialogDescription>
                            Kosongkan password untuk tetap memakai yang
                            sekarang.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="edit-ftp-directory">Folder</Label>
                        <AffixInput
                            id="edit-ftp-directory"
                            name="directory"
                            prefix={`${home}/`}
                            value={directory}
                            onChange={(event) =>
                                setDirectory(event.target.value)
                            }
                            maxLength={maxDirectory}
                            placeholder="kosongkan untuk seluruh home"
                            invalid={Boolean(errors.directory)}
                        />
                        <InputError message={errors.directory} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="edit-ftp-password">Password baru</Label>
                        <GeneratedPasswordInput
                            id="edit-ftp-password"
                            value={password}
                            onChange={setPassword}
                            required={false}
                            placeholder="Tetap memakai password sekarang"
                            invalid={Boolean(errors.password)}
                        />
                        <InputError message={errors.password} />
                    </div>

                    <QuotaField
                        id="edit-ftp-quota"
                        value={quota}
                        onChange={setQuota}
                        maxQuotaMb={maxQuotaMb}
                        error={errors.quota_mb}
                    />

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Batal
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={processing}>
                            {processing ? <Spinner /> : <KeyRound />}
                            Simpan
                        </Button>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}

/**
 * How many megabytes a login may fill its folder with through FTP; empty is
 * no limit.
 */
function QuotaField({
    id,
    value,
    onChange,
    maxQuotaMb,
    disabled = false,
    error,
}: {
    id: string;
    value: string;
    onChange: (value: string) => void;
    maxQuotaMb: number;
    disabled?: boolean;
    error?: string;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>Kuota</Label>
            <div className="sm:max-w-56">
                <AffixInput
                    id={id}
                    name="quota_mb"
                    type="number"
                    inputMode="numeric"
                    min={1}
                    max={maxQuotaMb}
                    step={1}
                    suffix="MB"
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                    disabled={disabled}
                    placeholder="Tanpa batas"
                    invalid={Boolean(error)}
                />
            </div>
            <p className="text-xs text-muted-foreground">
                Batas isi folder saat mengunggah lewat akun ini. Kosongkan untuk
                tanpa batas.
            </p>
            <InputError message={error} />
        </div>
    );
}

/**
 * SFTP for the account's own Linux user: where to connect, and its password,
 * which is separate from the panel password.
 */
function SftpPanel({ sftp, host }: { sftp: SftpState; host: string }) {
    const [password, setPassword] = useState('');

    return (
        <Panel
            title="SFTP (akun utama)"
            description="Akses semua file di home kamu lewat SFTP, dengan password khusus SFTP (berbeda dari password panel)."
        >
            <div className="grid gap-6 lg:grid-cols-2">
                <dl className="grid content-start gap-x-8 gap-y-3 text-sm sm:grid-cols-[auto_1fr]">
                    <dt className="text-muted-foreground">Host</dt>
                    <dd className="font-mono font-semibold break-all">
                        {host}
                    </dd>
                    <dt className="text-muted-foreground">Port</dt>
                    <dd className="font-mono font-semibold">{sftp.port}</dd>
                    <dt className="text-muted-foreground">Username</dt>
                    <dd className="font-mono font-semibold">{sftp.username}</dd>
                    <dt className="text-muted-foreground">Password</dt>
                    <dd>
                        {sftp.password_set_at
                            ? `Sudah diatur (${formatDateTime(sftp.password_set_at)})`
                            : 'Belum ada; atur dulu di sini.'}
                    </dd>
                </dl>

                <div className="grid content-start gap-4">
                    {sftp.pending && (
                        <p className="flex items-center gap-2 text-sm text-muted-foreground">
                            <Spinner /> Password sedang diterapkan ke server…
                        </p>
                    )}
                    {sftp.error !== null && (
                        <p className="text-sm text-destructive-foreground">
                            Password terakhir gagal diterapkan
                            {sftp.error !== '' ? `: ${sftp.error}` : '.'}
                        </p>
                    )}

                    <Form
                        {...setSftpPassword.form()}
                        options={{ preserveScroll: true }}
                        onSuccess={() => setPassword('')}
                        className="grid gap-3"
                    >
                        {({ processing, errors }) => (
                            <>
                                <Label htmlFor="sftp-password">
                                    {sftp.password_set_at
                                        ? 'Password SFTP baru'
                                        : 'Password SFTP'}
                                </Label>
                                <GeneratedPasswordInput
                                    id="sftp-password"
                                    value={password}
                                    onChange={setPassword}
                                    required
                                    placeholder="Minimal 12 karakter"
                                    invalid={Boolean(errors.password)}
                                />
                                <InputError message={errors.password} />
                                <div>
                                    <Button type="submit" disabled={processing}>
                                        {processing ? (
                                            <Spinner />
                                        ) : (
                                            <KeyRound />
                                        )}
                                        Simpan password SFTP
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>

                    {sftp.password_set_at && (
                        <Form
                            {...removeSftpPassword.form()}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    variant="ghost"
                                    size="sm"
                                    disabled={processing}
                                    className="text-muted-foreground"
                                >
                                    Hapus password SFTP (login hanya dengan SSH
                                    key)
                                </Button>
                            )}
                        </Form>
                    )}

                    <p className="text-xs text-muted-foreground">
                        File yang diunggah milik akun kamu dan terhitung di
                        kuota disk. Password ini juga dipakai untuk SSH, kalau
                        paket kamu termasuk SSH.
                    </p>
                </div>
            </div>
        </Panel>
    );
}
