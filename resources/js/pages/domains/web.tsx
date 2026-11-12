import { Deferred, Form, Head, Link, usePoll } from '@inertiajs/react';
import {
    ArrowLeft,
    FileWarning,
    FolderLock,
    KeyRound,
    Plus,
    Trash2,
    UserPlus,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useState } from 'react';
import { GeneratedPasswordInput } from '@/components/generated-password-input';
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
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { index as domainsIndex } from '@/routes/domains';
import {
    destroy as destroyErrorPage,
    update as updateErrorPage,
} from '@/routes/domains/error-pages';
import { formatDateTime } from '@/lib/format';
import { update as updateCgi } from '@/routes/domains/cgi';
import { update as updateLimits } from '@/routes/domains/limits';
import { update as updateHotlink } from '@/routes/domains/hotlink';
import { update as updateMimeTypes } from '@/routes/domains/mime-types';
import { update as updatePhpExtensions } from '@/routes/domains/php-extensions';
import {
    destroy as destroyDirectory,
    store as storeDirectory,
} from '@/routes/domains/protected-directories';
import {
    destroy as destroyLogin,
    store as storeLogin,
    update as updateLogin,
} from '@/routes/domains/protected-directories/logins';

type Login = { id: number; username: string };

type ProtectedDirectory = {
    id: number;
    path: string;
    realm: string;
    logins: Login[];
};

type Hotlink = {
    extensions: string[];
    domains: string[];
    allow_direct: boolean;
    redirect_to: string | null;
};

type MimeType = { extension: string; type: string };

type Props = {
    domain: {
        id: number;
        name: string;
        display_name: string;
        status: string;
        php_enabled: boolean;
        document_root: string;
        aliases: string[];
    };
    error_pages: number[];
    error_page_contents?: Record<string, string | null>;
    protected_directories: ProtectedDirectory[];
    hotlink: Hotlink | null;
    mime_types: MimeType[];
    php_extensions: string[];
    cgi: { enabled: boolean; allowed: boolean };
    limits: {
        bandwidth_limit_mb: number | null;
        disk_limit_mb: number | null;
        bandwidth_used_mb: number;
        disk_used_mb: number | null;
        disk_measured_at: string | null;
        reached: 'bandwidth' | 'disk' | null;
    };
    options: {
        error_page_statuses: number[];
        static_extensions: string[];
        default_hotlink_extensions: string[];
        max_protected_directories: number;
        max_protected_logins: number;
        max_mime_types: number;
        max_php_extensions: number;
    };
};

const statusTitles: Record<number, string> = {
    400: 'Permintaan tidak valid',
    401: 'Perlu login',
    403: 'Akses ditolak',
    404: 'Halaman tidak ditemukan',
    500: 'Kesalahan server',
    503: 'Sedang tidak tersedia',
};

const textareaClass =
    'w-full rounded-xl border border-input bg-card px-3.5 py-2.5 font-mono text-xs shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive aria-invalid:ring-destructive/20';

/** A plain page to start from when a website has no page of its own yet. */
function defaultErrorPage(status: number, domain: string): string {
    const title = statusTitles[status] ?? 'Terjadi kesalahan';

    return `<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>${status} - ${title}</title>
</head>
<body style="font-family: sans-serif; max-width: 36rem; margin: 15vh auto; padding: 0 1rem; color: #333">
    <h1>${title}</h1>
    <p>Kembali ke <a href="/">${domain}</a>.</p>
</body>
</html>
`;
}

/** The first error of a list field: the list's own, or one of its items'. */
function listError(
    errors: Record<string, string>,
    field: string,
): string | undefined {
    return (
        errors[field] ??
        Object.entries(errors).find(([key]) => key.startsWith(`${field}.`))?.[1]
    );
}

export default function DomainWebSettings({
    domain,
    error_pages,
    error_page_contents,
    protected_directories,
    hotlink,
    mime_types,
    php_extensions,
    cgi,
    limits,
    options,
}: Props) {
    const isBusy = domain.status === 'pending';
    const { start, stop } = usePoll(
        3000,
        { only: ['domain'] },
        { autoStart: false },
    );

    useEffect(() => {
        if (isBusy) {
            start();
        } else {
            stop();
        }
    }, [isBusy, start, stop]);

    return (
        <>
            <Head title={`Pengaturan web ${domain.display_name}`} />

            <div>
                <Button variant="ghost" size="sm" asChild className="-ml-2">
                    <Link href={domainsIndex()}>
                        <ArrowLeft /> Domains
                    </Link>
                </Button>
            </div>

            <PageHeader
                title="Pengaturan web"
                description={
                    <>
                        Halaman error, folder berpassword, proteksi hotlink,
                        tipe MIME dan ekstensi PHP untuk{' '}
                        <span className="font-semibold text-foreground">
                            {domain.display_name}
                        </span>
                        {domain.aliases.length > 0 &&
                            `, juga untuk alias-nya: ${domain.aliases.join(', ')}`}
                        .
                    </>
                }
                actions={
                    <StatusBadge
                        status={domain.status}
                        label={
                            isBusy
                                ? 'Sedang diterapkan'
                                : domain.status === 'failed'
                                  ? 'Gagal diterapkan'
                                  : undefined
                        }
                    />
                }
            />

            {limits.reached !== null && (
                <div className="rounded-2xl border border-amber-300 bg-amber-50 px-5 py-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
                    <span className="font-semibold">
                        Website sedang dihentikan:
                    </span>{' '}
                    {limits.reached === 'bandwidth'
                        ? 'batas bandwidth bulan ini sudah terpakai. Website jalan lagi di awal bulan berikutnya, atau setelah batasnya dinaikkan.'
                        : 'folder website lebih besar dari batas disk-nya. Website jalan lagi setelah file dikurangi (dicek tiap jam) atau batasnya dinaikkan.'}{' '}
                    Pengunjung melihat halaman “sementara tidak tersedia”.
                </div>
            )}

            <ErrorPagesPanel
                domain={domain}
                enabled={error_pages}
                contents={error_page_contents}
                statuses={options.error_page_statuses}
            />

            <ProtectedDirectoriesPanel
                domain={domain}
                directories={protected_directories}
                maxDirectories={options.max_protected_directories}
                maxLogins={options.max_protected_logins}
            />

            <HotlinkPanel
                key={JSON.stringify(hotlink)}
                domain={domain}
                hotlink={hotlink}
                staticExtensions={options.static_extensions}
                defaultExtensions={options.default_hotlink_extensions}
            />

            <div className="grid gap-6 xl:grid-cols-2">
                <MimeTypesPanel
                    key={JSON.stringify(mime_types)}
                    domain={domain}
                    mimeTypes={mime_types}
                    max={options.max_mime_types}
                />
                <PhpExtensionsPanel
                    key={php_extensions.join(',')}
                    domain={domain}
                    extensions={php_extensions}
                    max={options.max_php_extensions}
                />
            </div>

            <div className="grid gap-6 xl:grid-cols-2">
                <LimitsPanel
                    key={`${limits.bandwidth_limit_mb}-${limits.disk_limit_mb}`}
                    domain={domain}
                    limits={limits}
                />
                <CgiPanel domain={domain} cgi={cgi} />
            </div>
        </>
    );
}

function ErrorPagesPanel({
    domain,
    enabled,
    contents,
    statuses,
}: {
    domain: Props['domain'];
    enabled: number[];
    contents: Props['error_page_contents'];
    statuses: number[];
}) {
    const [editing, setEditing] = useState<number | null>(null);
    const [resetting, setResetting] = useState<number | null>(null);

    return (
        <Panel
            title="Halaman error"
            description={`Tampilkan halaman sendiri saat terjadi error. Disimpan di folder ${domain.document_root}/.errors/.`}
            flush
        >
            <div className="overflow-x-auto">
                <table className={tableStyles.table}>
                    <thead className={tableStyles.head}>
                        <tr>
                            <th className={tableStyles.th}>Kode</th>
                            <th className={tableStyles.th}>Halaman</th>
                            <th className={tableStyles.th}>
                                <span className="sr-only">Aksi</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {statuses.map((status) => {
                            const isEnabled = enabled.includes(status);

                            return (
                                <tr key={status} className={tableStyles.row}>
                                    <td className={tableStyles.td}>
                                        <div className="font-semibold">
                                            {status}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {statusTitles[status]}
                                            {status === 503 &&
                                                ' (juga 502 dan 504)'}
                                        </div>
                                    </td>
                                    <td className={tableStyles.td}>
                                        {isEnabled ? (
                                            <StatusBadge
                                                status="active"
                                                label="Halaman sendiri"
                                            />
                                        ) : (
                                            <span className="text-sm text-muted-foreground">
                                                Bawaan server
                                            </span>
                                        )}
                                    </td>
                                    <td className={tableStyles.td}>
                                        <div className="flex justify-end gap-2">
                                            {isEnabled && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        setResetting(status)
                                                    }
                                                >
                                                    Pakai bawaan
                                                </Button>
                                            )}
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    setEditing(status)
                                                }
                                            >
                                                <FileWarning />
                                                {isEnabled ? 'Ubah' : 'Buat'}
                                            </Button>
                                        </div>
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>

            <Dialog
                open={editing !== null}
                onOpenChange={(open) => !open && setEditing(null)}
            >
                <DialogContent className="rounded-2xl sm:max-w-3xl">
                    {editing !== null && (
                        <Deferred
                            data="error_page_contents"
                            fallback={<Skeleton className="h-80 w-full" />}
                        >
                            <ErrorPageForm
                                key={editing}
                                domain={domain}
                                status={editing}
                                content={
                                    enabled.includes(editing)
                                        ? (contents?.[editing] ?? null)
                                        : null
                                }
                                onSuccess={() => setEditing(null)}
                            />
                        </Deferred>
                    )}
                </DialogContent>
            </Dialog>

            <Dialog
                open={resetting !== null}
                onOpenChange={(open) => !open && setResetting(null)}
            >
                <DialogContent className="rounded-2xl">
                    {resetting !== null && (
                        <ConfirmForm
                            form={destroyErrorPage.form({
                                domain: domain.id,
                                status: resetting,
                            })}
                            title={`Pakai halaman ${resetting} bawaan?`}
                            description="Server kembali menampilkan halaman bawaannya. File halaman kamu tetap ada di folder .errors."
                            confirm="Pakai bawaan"
                            errorKey="status"
                            onSuccess={() => setResetting(null)}
                        />
                    )}
                </DialogContent>
            </Dialog>
        </Panel>
    );
}

function ErrorPageForm({
    domain,
    status,
    content,
    onSuccess,
}: {
    domain: Props['domain'];
    status: number;
    content: string | null;
    onSuccess: () => void;
}) {
    const [value, setValue] = useState(
        () => content ?? defaultErrorPage(status, domain.display_name),
    );

    return (
        <Form
            {...updateErrorPage.form({ domain: domain.id, status })}
            options={{ preserveScroll: true }}
            transform={() => ({ content: value })}
            onSuccess={onSuccess}
            className="grid gap-5"
        >
            {({ processing, errors }) => (
                <>
                    <DialogHeader>
                        <DialogTitle>
                            Halaman {status}: {statusTitles[status]}
                        </DialogTitle>
                        <DialogDescription>
                            HTML yang dilihat pengunjung {domain.display_name}{' '}
                            saat terjadi error {status}, disimpan sebagai
                            .errors/{status}.html. Ambil gambar dan CSS-nya dari
                            folder lain di website, atau tulis langsung di HTML.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="content">HTML</Label>
                        <textarea
                            id="content"
                            value={value}
                            onChange={(event) => setValue(event.target.value)}
                            required
                            spellCheck={false}
                            className={`${textareaClass} min-h-72`}
                            aria-invalid={errors.content ? true : undefined}
                        />
                        <InputError message={errors.content} />
                    </div>

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button variant="secondary">Batal</Button>
                        </DialogClose>
                        <Button disabled={processing}>
                            {processing && <Spinner />}
                            Simpan
                        </Button>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}

type DirectoryAction =
    | { type: 'unprotect'; directory: ProtectedDirectory }
    | { type: 'add-login'; directory: ProtectedDirectory }
    | { type: 'password'; directory: ProtectedDirectory; login: Login }
    | { type: 'remove-login'; directory: ProtectedDirectory; login: Login };

function ProtectedDirectoriesPanel({
    domain,
    directories,
    maxDirectories,
    maxLogins,
}: {
    domain: Props['domain'];
    directories: ProtectedDirectory[];
    maxDirectories: number;
    maxLogins: number;
}) {
    const [action, setAction] = useState<DirectoryAction | null>(null);
    const close = () => setAction(null);

    return (
        <Panel
            title="Folder berpassword"
            description={`Pengunjung harus login dulu sebelum membuka folder ini. Paling banyak ${maxDirectories} folder, ${maxLogins} login per folder.`}
        >
            <div className="grid gap-6">
                <Form
                    {...storeDirectory.form(domain.id)}
                    options={{ preserveScroll: true }}
                    resetOnSuccess={['path']}
                    className="grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-start"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="path">Folder</Label>
                                <Input
                                    id="path"
                                    name="path"
                                    required
                                    placeholder="/admin (atau / untuk seluruh website)"
                                    aria-invalid={
                                        errors.path ? true : undefined
                                    }
                                />
                                <InputError message={errors.path} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="realm">
                                    Teks di jendela login
                                </Label>
                                <Input
                                    id="realm"
                                    name="realm"
                                    required
                                    maxLength={64}
                                    defaultValue="Area terbatas"
                                    aria-invalid={
                                        errors.realm ? true : undefined
                                    }
                                />
                                <InputError message={errors.realm} />
                            </div>
                            <Button
                                disabled={processing}
                                className="sm:mt-[1.625rem]"
                            >
                                {processing ? <Spinner /> : <FolderLock />}
                                Lindungi
                            </Button>
                        </>
                    )}
                </Form>

                {directories.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Belum ada folder yang dilindungi.
                    </p>
                ) : (
                    <ul className="grid gap-4">
                        {directories.map((directory) => (
                            <li
                                key={directory.id}
                                className="rounded-xl border p-4"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <div className="font-semibold break-all">
                                            {domain.display_name}
                                            <span className="text-primary">
                                                {directory.path}
                                            </span>
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            “{directory.realm}”
                                        </div>
                                    </div>
                                    <div className="flex gap-2">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                setAction({
                                                    type: 'add-login',
                                                    directory,
                                                })
                                            }
                                        >
                                            <UserPlus /> Tambah login
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            aria-label={`Buka lagi ${directory.path}`}
                                            onClick={() =>
                                                setAction({
                                                    type: 'unprotect',
                                                    directory,
                                                })
                                            }
                                        >
                                            <Trash2 />
                                        </Button>
                                    </div>
                                </div>

                                {directory.logins.length === 0 ? (
                                    <p className="mt-3 text-sm text-amber-700 dark:text-amber-400">
                                        Belum ada login, jadi belum ada yang
                                        bisa membuka folder ini.
                                    </p>
                                ) : (
                                    <ul className="mt-3 divide-y rounded-lg border">
                                        {directory.logins.map((login) => (
                                            <li
                                                key={login.id}
                                                className="flex items-center justify-between gap-3 px-3 py-2"
                                            >
                                                <span className="font-mono text-sm">
                                                    {login.username}
                                                </span>
                                                <div className="flex gap-1">
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            setAction({
                                                                type: 'password',
                                                                directory,
                                                                login,
                                                            })
                                                        }
                                                    >
                                                        <KeyRound /> Ganti
                                                        password
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        aria-label={`Hapus login ${login.username}`}
                                                        onClick={() =>
                                                            setAction({
                                                                type: 'remove-login',
                                                                directory,
                                                                login,
                                                            })
                                                        }
                                                    >
                                                        <Trash2 />
                                                    </Button>
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <Dialog
                open={action !== null}
                onOpenChange={(open) => !open && close()}
            >
                <DialogContent className="rounded-2xl">
                    {action?.type === 'add-login' && (
                        <LoginForm
                            domain={domain}
                            directory={action.directory}
                            onSuccess={close}
                        />
                    )}
                    {action?.type === 'password' && (
                        <LoginForm
                            domain={domain}
                            directory={action.directory}
                            login={action.login}
                            onSuccess={close}
                        />
                    )}
                    {action?.type === 'unprotect' && (
                        <ConfirmForm
                            form={destroyDirectory.form({
                                domain: domain.id,
                                protectedDirectory: action.directory.id,
                            })}
                            title={`Buka lagi ${action.directory.path}?`}
                            description="Folder ini bisa dibuka semua orang lagi, dan semua login-nya dihapus."
                            confirm="Buka untuk umum"
                            errorKey="path"
                            onSuccess={close}
                        />
                    )}
                    {action?.type === 'remove-login' && (
                        <ConfirmForm
                            form={destroyLogin.form({
                                domain: domain.id,
                                protectedDirectory: action.directory.id,
                                login: action.login.id,
                            })}
                            title={`Hapus login ${action.login.username}?`}
                            description={`Login ini tidak bisa lagi membuka ${action.directory.path}.`}
                            confirm="Hapus login"
                            errorKey="username"
                            onSuccess={close}
                        />
                    )}
                </DialogContent>
            </Dialog>
        </Panel>
    );
}

function LoginForm({
    domain,
    directory,
    login,
    onSuccess,
}: {
    domain: Props['domain'];
    directory: ProtectedDirectory;
    login?: Login;
    onSuccess: () => void;
}) {
    const [password, setPassword] = useState('');
    const form = login
        ? updateLogin.form({
              domain: domain.id,
              protectedDirectory: directory.id,
              login: login.id,
          })
        : storeLogin.form({
              domain: domain.id,
              protectedDirectory: directory.id,
          });

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
                            {login
                                ? `Password baru untuk ${login.username}`
                                : `Login baru untuk ${directory.path}`}
                        </DialogTitle>
                        <DialogDescription>
                            Diisi di jendela login browser saat membuka{' '}
                            {domain.display_name}
                            {directory.path}. Simpan password-nya, panel tidak
                            bisa menampilkannya lagi.
                        </DialogDescription>
                    </DialogHeader>

                    {!login && (
                        <div className="grid gap-2">
                            <Label htmlFor="username">Nama login</Label>
                            <Input
                                id="username"
                                name="username"
                                required
                                maxLength={64}
                                autoComplete="off"
                                aria-invalid={
                                    errors.username ? true : undefined
                                }
                            />
                            <InputError message={errors.username} />
                        </div>
                    )}

                    <div className="grid gap-2">
                        <Label htmlFor="password">Password</Label>
                        <GeneratedPasswordInput
                            id="password"
                            value={password}
                            onChange={setPassword}
                            required
                            placeholder="Minimal 12 karakter"
                            invalid={Boolean(errors.password)}
                        />
                        <InputError message={errors.password} />
                    </div>

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button variant="secondary">Batal</Button>
                        </DialogClose>
                        <Button disabled={processing}>
                            {processing && <Spinner />}
                            {login ? 'Ganti password' : 'Tambah login'}
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
    confirm,
    errorKey,
    onSuccess,
}: {
    form: ReturnType<typeof destroyDirectory.form>;
    title: string;
    description: string;
    confirm: string;
    errorKey: string;
    onSuccess: () => void;
}) {
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
                        <DialogTitle>{title}</DialogTitle>
                        <DialogDescription>{description}</DialogDescription>
                    </DialogHeader>
                    <InputError
                        message={(errors as Record<string, string>)[errorKey]}
                    />
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button variant="secondary">Batal</Button>
                        </DialogClose>
                        <Button variant="destructive" disabled={processing}>
                            {processing && <Spinner />}
                            {confirm}
                        </Button>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}

function HotlinkPanel({
    domain,
    hotlink,
    staticExtensions,
    defaultExtensions,
}: {
    domain: Props['domain'];
    hotlink: Hotlink | null;
    staticExtensions: string[];
    defaultExtensions: string[];
}) {
    const [enabled, setEnabled] = useState(hotlink !== null);
    const [extensions, setExtensions] = useState<string[]>(
        hotlink?.extensions ?? defaultExtensions,
    );
    const [domains, setDomains] = useState((hotlink?.domains ?? []).join('\n'));
    const [allowDirect, setAllowDirect] = useState(
        hotlink?.allow_direct ?? true,
    );
    const [redirectTo, setRedirectTo] = useState(hotlink?.redirect_to ?? '');

    const toggleExtension = (extension: string, checked: boolean) =>
        setExtensions((current) =>
            checked
                ? [...current, extension]
                : current.filter((value) => value !== extension),
        );

    return (
        <Panel
            title="Proteksi hotlink"
            description={`Cegah website lain menampilkan file dari ${domain.display_name}, misalnya gambar, di halaman mereka dan menghabiskan bandwidth kamu.`}
        >
            <Form
                {...updateHotlink.form(domain.id)}
                options={{ preserveScroll: true }}
                transform={() => ({
                    enabled,
                    extensions,
                    domains,
                    allow_direct: allowDirect,
                    redirect_to: redirectTo,
                })}
                className="grid gap-5"
            >
                {({ processing, errors }) => (
                    <>
                        <Field
                            label="Aktifkan proteksi hotlink"
                            htmlFor="hotlink-enabled"
                        >
                            <Checkbox
                                id="hotlink-enabled"
                                checked={enabled}
                                onCheckedChange={(checked) =>
                                    setEnabled(checked === true)
                                }
                            />
                        </Field>

                        {enabled && (
                            <>
                                <div className="grid gap-2">
                                    <Label>File yang dilindungi</Label>
                                    <div className="grid grid-cols-3 gap-2 sm:grid-cols-6 lg:grid-cols-10">
                                        {staticExtensions.map((extension) => (
                                            <label
                                                key={extension}
                                                className="flex items-center gap-2 text-sm"
                                            >
                                                <Checkbox
                                                    checked={extensions.includes(
                                                        extension,
                                                    )}
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
                                                        toggleExtension(
                                                            extension,
                                                            checked === true,
                                                        )
                                                    }
                                                />
                                                .{extension}
                                            </label>
                                        ))}
                                    </div>
                                    <InputError
                                        message={listError(
                                            errors,
                                            'extensions',
                                        )}
                                    />
                                </div>

                                <div className="grid gap-5 lg:grid-cols-2 lg:gap-6">
                                    <div className="grid content-start gap-2">
                                        <Label htmlFor="hotlink-domains">
                                            Website lain yang boleh
                                        </Label>
                                        <textarea
                                            id="hotlink-domains"
                                            value={domains}
                                            onChange={(event) =>
                                                setDomains(event.target.value)
                                            }
                                            spellCheck={false}
                                            placeholder={
                                                'partner-saya.com\ncdn.contoh.net'
                                            }
                                            className={`${textareaClass} min-h-24`}
                                            aria-invalid={
                                                listError(errors, 'domains')
                                                    ? true
                                                    : undefined
                                            }
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            Satu domain per baris, subdomain-nya
                                            ikut. {domain.display_name} dan
                                            subdomain-nya selalu boleh.
                                        </p>
                                        <InputError
                                            message={listError(
                                                errors,
                                                'domains',
                                            )}
                                        />
                                    </div>

                                    <div className="grid content-start gap-4">
                                        <Field
                                            label="Izinkan akses langsung"
                                            description="Alamat file yang diketik sendiri, bookmark atau aplikasi (tanpa referrer)."
                                            htmlFor="hotlink-direct"
                                        >
                                            <Checkbox
                                                id="hotlink-direct"
                                                checked={allowDirect}
                                                onCheckedChange={(checked) =>
                                                    setAllowDirect(
                                                        checked === true,
                                                    )
                                                }
                                            />
                                        </Field>

                                        <div className="grid gap-2">
                                            <Label htmlFor="hotlink-redirect">
                                                Alihkan ke (opsional)
                                            </Label>
                                            <Input
                                                id="hotlink-redirect"
                                                value={redirectTo}
                                                onChange={(event) =>
                                                    setRedirectTo(
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder="https://website-lain.com/dilarang.png"
                                                aria-invalid={
                                                    errors.redirect_to
                                                        ? true
                                                        : undefined
                                                }
                                            />
                                            <p className="text-xs text-muted-foreground">
                                                Kosongkan untuk menjawab 403
                                                (akses ditolak).
                                            </p>
                                            <InputError
                                                message={errors.redirect_to}
                                            />
                                        </div>
                                    </div>
                                </div>
                            </>
                        )}

                        <InputError message={errors.hotlink} />

                        <div>
                            <Button disabled={processing}>
                                {processing && <Spinner />}
                                Simpan
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </Panel>
    );
}

function MimeTypesPanel({
    domain,
    mimeTypes,
    max,
}: {
    domain: Props['domain'];
    mimeTypes: MimeType[];
    max: number;
}) {
    const [rows, setRows] = useState<MimeType[]>(mimeTypes);

    const setRow = (index: number, row: Partial<MimeType>) =>
        setRows((current) =>
            current.map((value, i) =>
                i === index ? { ...value, ...row } : value,
            ),
        );

    return (
        <Panel
            title="Tipe MIME"
            description="Atur Content-Type yang dikirim untuk ekstensi file tertentu."
        >
            <Form
                {...updateMimeTypes.form(domain.id)}
                options={{ preserveScroll: true }}
                transform={() => ({ mime_types: rows })}
                className="grid gap-4"
            >
                {({ processing, errors }) => {
                    const fieldErrors = errors as Record<string, string>;

                    return (
                        <>
                            {rows.length === 0 && (
                                <p className="text-sm text-muted-foreground">
                                    Belum ada; semua file memakai tipe bawaan
                                    server.
                                </p>
                            )}
                            {rows.map((row, index) => {
                                const extensionError =
                                    fieldErrors[
                                        `mime_types.${index}.extension`
                                    ];
                                const typeError =
                                    fieldErrors[`mime_types.${index}.type`];

                                return (
                                    <div key={index} className="grid gap-1">
                                        <div className="grid grid-cols-[7rem_1fr_auto] gap-2">
                                            <Input
                                                value={row.extension}
                                                onChange={(event) =>
                                                    setRow(index, {
                                                        extension:
                                                            event.target.value,
                                                    })
                                                }
                                                placeholder="ekstensi"
                                                aria-label="Ekstensi"
                                                className="font-mono"
                                                aria-invalid={
                                                    extensionError
                                                        ? true
                                                        : undefined
                                                }
                                            />
                                            <Input
                                                value={row.type}
                                                onChange={(event) =>
                                                    setRow(index, {
                                                        type: event.target
                                                            .value,
                                                    })
                                                }
                                                placeholder="application/x-contoh"
                                                aria-label="Tipe MIME"
                                                className="font-mono"
                                                aria-invalid={
                                                    typeError ? true : undefined
                                                }
                                            />
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                aria-label="Hapus baris"
                                                onClick={() =>
                                                    setRows((current) =>
                                                        current.filter(
                                                            (_, i) =>
                                                                i !== index,
                                                        ),
                                                    )
                                                }
                                            >
                                                <Trash2 />
                                            </Button>
                                        </div>
                                        <InputError
                                            message={
                                                extensionError ?? typeError
                                            }
                                        />
                                    </div>
                                );
                            })}
                            <InputError message={errors.mime_types} />

                            <div className="flex flex-wrap gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={rows.length >= max}
                                    onClick={() =>
                                        setRows((current) => [
                                            ...current,
                                            { extension: '', type: '' },
                                        ])
                                    }
                                >
                                    <Plus /> Tambah
                                </Button>
                                <Button disabled={processing}>
                                    {processing && <Spinner />}
                                    Simpan
                                </Button>
                            </div>
                        </>
                    );
                }}
            </Form>
        </Panel>
    );
}

function PhpExtensionsPanel({
    domain,
    extensions,
    max,
}: {
    domain: Props['domain'];
    extensions: string[];
    max: number;
}) {
    const [value, setValue] = useState(extensions.join(' '));
    const list = value
        .split(/[\s,]+/)
        .map((extension) => extension.trim())
        .filter((extension) => extension !== '');

    return (
        <Panel
            title="Ekstensi PHP"
            description="Jalankan file dengan ekstensi lain sebagai PHP juga, misalnya .html. File .php dan .phtml selalu jalan sebagai PHP."
        >
            <Form
                {...updatePhpExtensions.form(domain.id)}
                options={{ preserveScroll: true }}
                transform={() => ({ php_extensions: list })}
                className="grid gap-4"
            >
                {({ processing, errors }) => (
                    <>
                        {!domain.php_enabled && (
                            <p className="text-sm text-amber-700 dark:text-amber-400">
                                PHP sedang dimatikan untuk website ini, jadi
                                ekstensi ini baru berlaku setelah PHP dinyalakan
                                lagi.
                            </p>
                        )}
                        <div className="grid gap-2">
                            <Label htmlFor="php-extensions">Ekstensi</Label>
                            <Input
                                id="php-extensions"
                                value={value}
                                onChange={(event) =>
                                    setValue(event.target.value)
                                }
                                placeholder="html htm"
                                className="font-mono"
                                aria-invalid={
                                    listError(errors, 'php_extensions')
                                        ? true
                                        : undefined
                                }
                            />
                            <p className="text-xs text-muted-foreground">
                                Pisahkan dengan spasi, paling banyak {max}.
                                Gambar, CSS, JS dan file statis lain tidak bisa
                                dijalankan sebagai PHP.
                            </p>
                            <InputError
                                message={listError(errors, 'php_extensions')}
                            />
                        </div>
                        <div>
                            <Button disabled={processing}>
                                {processing && <Spinner />}
                                Simpan
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </Panel>
    );
}

function LimitsPanel({
    domain,
    limits,
}: {
    domain: Props['domain'];
    limits: Props['limits'];
}) {
    return (
        <Panel
            title="Batas website"
            description="Batas pemakaian website ini saja, di dalam batas paket akun. Kosongkan untuk tanpa batas."
        >
            <Form
                {...updateLimits.form(domain.id)}
                options={{ preserveScroll: true }}
                className="grid gap-4"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="bandwidth_limit_mb">
                                    Bandwidth per bulan (MB)
                                </Label>
                                <Input
                                    id="bandwidth_limit_mb"
                                    name="bandwidth_limit_mb"
                                    type="number"
                                    min={1}
                                    defaultValue={
                                        limits.bandwidth_limit_mb ?? ''
                                    }
                                    placeholder="Tanpa batas"
                                    aria-invalid={
                                        errors.bandwidth_limit_mb
                                            ? true
                                            : undefined
                                    }
                                />
                                <p className="text-xs text-muted-foreground">
                                    Bulan ini terpakai{' '}
                                    {limits.bandwidth_used_mb.toLocaleString(
                                        'id-ID',
                                    )}{' '}
                                    MB.
                                </p>
                                <InputError
                                    message={errors.bandwidth_limit_mb}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="disk_limit_mb">Disk (MB)</Label>
                                <Input
                                    id="disk_limit_mb"
                                    name="disk_limit_mb"
                                    type="number"
                                    min={1}
                                    defaultValue={limits.disk_limit_mb ?? ''}
                                    placeholder="Tanpa batas"
                                    aria-invalid={
                                        errors.disk_limit_mb ? true : undefined
                                    }
                                />
                                <p className="text-xs text-muted-foreground">
                                    {limits.disk_used_mb !== null &&
                                    limits.disk_measured_at
                                        ? `Terpakai ${limits.disk_used_mb.toLocaleString('id-ID')} MB (diukur ${formatDateTime(limits.disk_measured_at)}).`
                                        : 'Folder website diukur tiap jam setelah batasnya diatur.'}
                                </p>
                                <InputError message={errors.disk_limit_mb} />
                            </div>
                        </div>
                        <div>
                            <Button disabled={processing}>
                                {processing && <Spinner />}
                                Simpan
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </Panel>
    );
}

function CgiPanel({
    domain,
    cgi,
}: {
    domain: Props['domain'];
    cgi: Props['cgi'];
}) {
    return (
        <Panel
            title="CGI"
            description={`Jalankan skrip di folder ${domain.document_root}/cgi-bin (Perl, Python, shell, dan lainnya) sebagai akun kamu. Skrip harus bisa dieksekusi (chmod 755) dan tidak boleh bisa diubah orang lain.`}
        >
            <Form
                {...updateCgi.form(domain.id)}
                options={{ preserveScroll: true }}
                transform={() => ({ cgi_enabled: !cgi.enabled })}
                className="flex flex-wrap items-center gap-4"
            >
                {({ processing, errors }) => (
                    <>
                        <StatusBadge
                            status={
                                cgi.enabled && cgi.allowed
                                    ? 'active'
                                    : 'neutral'
                            }
                            label={
                                cgi.enabled && cgi.allowed
                                    ? 'Menyala'
                                    : cgi.enabled
                                      ? 'Berhenti: paket tidak termasuk CGI'
                                      : 'Mati'
                            }
                        />
                        {(cgi.allowed || cgi.enabled) && (
                            <Button
                                variant={cgi.enabled ? 'outline' : 'default'}
                                disabled={processing}
                            >
                                {processing && <Spinner />}
                                {cgi.enabled ? 'Matikan CGI' : 'Nyalakan CGI'}
                            </Button>
                        )}
                        {!cgi.allowed && (
                            <p className="text-sm text-muted-foreground">
                                Paket kamu tidak termasuk CGI.
                            </p>
                        )}
                        <InputError message={errors.cgi_enabled} />
                    </>
                )}
            </Form>
        </Panel>
    );
}

function Field({
    label,
    description,
    htmlFor,
    children,
}: {
    label: string;
    description?: string;
    htmlFor: string;
    children: ReactNode;
}) {
    return (
        <div className="flex items-start gap-3">
            <div className="pt-0.5">{children}</div>
            <div className="grid gap-0.5">
                <Label htmlFor={htmlFor}>{label}</Label>
                {description && (
                    <p className="text-xs text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
        </div>
    );
}
