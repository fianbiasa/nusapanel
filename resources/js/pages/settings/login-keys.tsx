import { Form, Head, router } from '@inertiajs/react';
import { Check, Copy, KeyRound, Plus, Terminal, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import InputError from '@/components/input-error';
import SettingsCard from '@/components/settings-card';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useClipboard } from '@/hooks/use-clipboard';
import { formatDate, formatRelative } from '@/lib/format';
import { destroy, store } from '@/routes/login-keys';

type LoginKey = {
    id: number;
    name: string;
    prefix: string;
    permissions: string[];
    allowed_ips: string[];
    expires_at: string | null;
    expired: boolean;
    last_used_at: string | null;
    last_used_ip: string | null;
    created_at: string | null;
};

type Props = {
    keys: LoginKey[];
    permissions: string[];
    max_keys: number;
    api_url: string;
};

const permissionLabels: Record<string, string> = {
    'accounts:read': 'Lihat akun hosting',
    'accounts:write': 'Buat, suspend, ubah paket, dan hapus akun hosting',
    'packages:read': 'Lihat paket',
    'domains:read': 'Lihat domain',
    'domains:write': 'Tambah dan hapus domain',
    'databases:read': 'Lihat database dan user database',
    'databases:write': 'Buat dan hapus database dan user database',
    'email:read': 'Lihat akun email',
    'email:write': 'Buat dan hapus akun email',
    'dns:read': 'Lihat zone dan record DNS',
    'dns:write': 'Tambah dan hapus record DNS',
};

/** The API's endpoints, with the permission each needs. */
const endpoints: {
    method: string;
    path: string;
    body?: string;
    permission: string | null;
}[] = [
    { method: 'GET', path: '/me', permission: null },
    { method: 'GET', path: '/packages', permission: 'packages:read' },
    {
        method: 'GET',
        path: '/accounts?status=&search=&page=',
        permission: 'accounts:read',
    },
    {
        method: 'GET',
        path: '/accounts/{username}',
        permission: 'accounts:read',
    },
    {
        method: 'POST',
        path: '/accounts',
        body: 'username, name, email, password, domain, package_id',
        permission: 'accounts:write',
    },
    {
        method: 'POST',
        path: '/accounts/{username}/suspend',
        body: 'reason',
        permission: 'accounts:write',
    },
    {
        method: 'POST',
        path: '/accounts/{username}/unsuspend',
        permission: 'accounts:write',
    },
    {
        method: 'PUT',
        path: '/accounts/{username}/package',
        body: 'package_id',
        permission: 'accounts:write',
    },
    {
        method: 'DELETE',
        path: '/accounts/{username}',
        permission: 'accounts:write',
    },
    { method: 'GET', path: '/domains', permission: 'domains:read' },
    {
        method: 'POST',
        path: '/domains',
        body: 'domain',
        permission: 'domains:write',
    },
    { method: 'DELETE', path: '/domains/{id}', permission: 'domains:write' },
    { method: 'GET', path: '/databases', permission: 'databases:read' },
    {
        method: 'POST',
        path: '/databases',
        body: 'name, create_user, user_name, password',
        permission: 'databases:write',
    },
    {
        method: 'DELETE',
        path: '/databases/{id}',
        permission: 'databases:write',
    },
    { method: 'GET', path: '/database-users', permission: 'databases:read' },
    {
        method: 'POST',
        path: '/database-users',
        body: 'name, password, databases (id)',
        permission: 'databases:write',
    },
    {
        method: 'DELETE',
        path: '/database-users/{id}',
        permission: 'databases:write',
    },
    { method: 'GET', path: '/mailboxes', permission: 'email:read' },
    {
        method: 'POST',
        path: '/mailboxes',
        body: 'domain_id, local_part, password, quota_mb',
        permission: 'email:write',
    },
    { method: 'DELETE', path: '/mailboxes/{id}', permission: 'email:write' },
    { method: 'GET', path: '/dns/zones', permission: 'dns:read' },
    {
        method: 'POST',
        path: '/dns/zones/{id}/records',
        body: 'type, name, content, ttl, priority',
        permission: 'dns:write',
    },
    { method: 'DELETE', path: '/dns/records/{id}', permission: 'dns:write' },
];

const textareaClass =
    'min-h-20 w-full rounded-xl border border-input bg-card px-3.5 py-2.5 font-mono text-xs shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive aria-invalid:ring-destructive/20';

export default function LoginKeys({
    keys,
    permissions,
    max_keys,
    api_url,
}: Props) {
    const [created, setCreated] = useState<{
        name: string;
        secret: string;
    } | null>(null);
    const [deleting, setDeleting] = useState<number | null>(null);

    // The new key comes once, as flash data, and is never sent again.
    useEffect(() => {
        return router.on('flash', (event) => {
            const flash = (event as CustomEvent).detail?.flash;

            if (flash?.login_key) {
                setCreated(flash.login_key as { name: string; secret: string });
            }
        });
    }, []);

    const isFull = keys.length >= max_keys;

    return (
        <>
            <Head title="Login key" />

            {created && (
                <CreatedKey
                    created={created}
                    apiUrl={api_url}
                    onDone={() => setCreated(null)}
                />
            )}

            <SettingsCard
                icon={KeyRound}
                title="Login key"
                description={`Kunci untuk memakai API RAYXO dari aplikasi lain, misalnya sistem billing. Key tidak memakai password maupun 2FA, jadi beri hanya izin yang perlu, batasi alamat IP-nya, dan simpan seperti password. Paling banyak ${max_keys} key.`}
            >
                {keys.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Belum ada login key.
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {keys.map((key) => (
                            <li key={key.id} className="grid gap-2 px-4 py-3">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div className="flex min-w-0 items-center gap-2">
                                        <span className="truncate font-semibold">
                                            {key.name}
                                        </span>
                                        <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-xs">
                                            {key.prefix}…
                                        </code>
                                        {key.expired && (
                                            <StatusBadge
                                                status="cancelled"
                                                label="Kedaluwarsa"
                                            />
                                        )}
                                    </div>
                                    {deleting === key.id ? (
                                        <div className="flex items-center gap-2">
                                            <span className="text-sm text-muted-foreground">
                                                Hapus key ini?
                                            </span>
                                            <Button
                                                size="sm"
                                                variant="destructive"
                                                onClick={() =>
                                                    router.delete(
                                                        destroy.url(key.id),
                                                        {
                                                            preserveScroll: true,
                                                            onFinish: () =>
                                                                setDeleting(
                                                                    null,
                                                                ),
                                                        },
                                                    )
                                                }
                                            >
                                                Hapus
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    setDeleting(null)
                                                }
                                            >
                                                Batal
                                            </Button>
                                        </div>
                                    ) : (
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            aria-label={`Hapus login key ${key.name}`}
                                            onClick={() => setDeleting(key.id)}
                                        >
                                            <Trash2 />
                                        </Button>
                                    )}
                                </div>
                                <div className="flex flex-wrap gap-1.5">
                                    {key.permissions.map((permission) => (
                                        <span
                                            key={permission}
                                            className="rounded-full bg-accent px-2 py-0.5 font-mono text-[11px] text-accent-foreground"
                                        >
                                            {permission}
                                        </span>
                                    ))}
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    {key.allowed_ips.length > 0
                                        ? `Hanya dari ${key.allowed_ips.join(', ')}`
                                        : 'Dari alamat mana pun'}
                                    {' · '}
                                    {key.expires_at
                                        ? `berlaku sampai ${formatDate(key.expires_at)}`
                                        : 'tanpa masa berlaku'}
                                    {' · '}
                                    {key.last_used_at
                                        ? `terakhir dipakai ${formatRelative(key.last_used_at)} dari ${key.last_used_ip ?? '?'}`
                                        : 'belum pernah dipakai'}
                                </p>
                            </li>
                        ))}
                    </ul>
                )}
            </SettingsCard>

            {!isFull && (
                <SettingsCard
                    icon={Plus}
                    title="Buat login key"
                    description="Key-nya ditampilkan sekali setelah dibuat; panel hanya menyimpan sidik jarinya."
                >
                    <Form
                        {...store.form()}
                        options={{ preserveScroll: true }}
                        resetOnSuccess
                        className="grid gap-5"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-1.5 sm:max-w-sm">
                                    <Label htmlFor="login-key-name">Nama</Label>
                                    <Input
                                        id="login-key-name"
                                        name="name"
                                        required
                                        maxLength={100}
                                        placeholder="WHMCS"
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <fieldset className="grid gap-2">
                                    <legend className="mb-1 text-sm font-medium">
                                        Izin
                                    </legend>
                                    {permissions.map((permission) => (
                                        <div
                                            key={permission}
                                            className="flex items-start gap-3"
                                        >
                                            <Checkbox
                                                id={`permission-${permission}`}
                                                name="permissions[]"
                                                value={permission}
                                                className="mt-0.5"
                                            />
                                            <Label
                                                htmlFor={`permission-${permission}`}
                                                className="grid gap-0.5 font-normal"
                                            >
                                                <span>
                                                    {permissionLabels[
                                                        permission
                                                    ] ?? permission}
                                                </span>
                                                <span className="font-mono text-xs text-muted-foreground">
                                                    {permission}
                                                </span>
                                            </Label>
                                        </div>
                                    ))}
                                    <InputError
                                        message={
                                            errors.permissions ??
                                            errors['permissions.0']
                                        }
                                    />
                                </fieldset>

                                <div className="grid gap-1.5">
                                    <Label htmlFor="login-key-ips">
                                        Alamat IP yang diizinkan
                                    </Label>
                                    <textarea
                                        id="login-key-ips"
                                        name="allowed_ips"
                                        spellCheck={false}
                                        placeholder={
                                            '203.0.113.10\n2001:db8::/48'
                                        }
                                        className={textareaClass}
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Satu alamat atau jaringan per baris.
                                        Kosongkan untuk mengizinkan dari mana
                                        pun.
                                    </p>
                                    <InputError
                                        message={
                                            errors.allowed_ips ??
                                            Object.entries(errors).find(
                                                ([field]) =>
                                                    field.startsWith(
                                                        'allowed_ips.',
                                                    ),
                                            )?.[1]
                                        }
                                    />
                                </div>

                                <div className="grid gap-1.5 sm:max-w-xs">
                                    <Label htmlFor="login-key-expires">
                                        Berlaku sampai (opsional)
                                    </Label>
                                    <Input
                                        id="login-key-expires"
                                        name="expires_at"
                                        type="date"
                                    />
                                    <InputError message={errors.expires_at} />
                                </div>

                                <div>
                                    <Button type="submit" disabled={processing}>
                                        {processing ? (
                                            <Spinner />
                                        ) : (
                                            <KeyRound />
                                        )}
                                        Buat login key
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </SettingsCard>
            )}

            <SettingsCard
                icon={Terminal}
                title="Memakai API"
                description="Kirim key sebagai bearer token. Jawabannya JSON; kesalahan isian dijawab 422 dengan pesan per kolom, dan pekerjaan di server (membuat akun, domain, database) berjalan di belakang: status-nya menunjukkan kapan selesai."
            >
                <pre className="overflow-x-auto rounded-xl border bg-muted/40 p-4 font-mono text-xs">
                    {`curl -H "Authorization: Bearer rxk_..." \\\n     -H "Accept: application/json" \\\n     ${api_url}/me`}
                </pre>
                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <tbody className="divide-y">
                            {endpoints
                                .filter(
                                    (endpoint) =>
                                        endpoint.permission === null ||
                                        permissions.includes(
                                            endpoint.permission,
                                        ),
                                )
                                .map((endpoint) => (
                                    <tr
                                        key={`${endpoint.method} ${endpoint.path}`}
                                    >
                                        <td className="px-3 py-2 font-mono text-xs font-semibold whitespace-nowrap">
                                            {endpoint.method}
                                        </td>
                                        <td className="px-3 py-2 font-mono text-xs whitespace-nowrap">
                                            {endpoint.path}
                                        </td>
                                        <td className="px-3 py-2 text-xs text-muted-foreground">
                                            {endpoint.body}
                                        </td>
                                        <td className="px-3 py-2 font-mono text-[11px] whitespace-nowrap text-muted-foreground">
                                            {endpoint.permission}
                                        </td>
                                    </tr>
                                ))}
                        </tbody>
                    </table>
                </div>
            </SettingsCard>
        </>
    );
}

/** The key just made, shown this once. */
function CreatedKey({
    created,
    apiUrl,
    onDone,
}: {
    created: { name: string; secret: string };
    apiUrl: string;
    onDone: () => void;
}) {
    const [copied, copy] = useClipboard();

    return (
        <SettingsCard
            icon={Check}
            title={`Login key ${created.name} dibuat`}
            description="Salin key ini sekarang dan simpan di tempat aman. Panel tidak menyimpannya, jadi key ini tidak bisa ditampilkan lagi."
            className="border-primary"
        >
            <div className="flex flex-wrap items-center gap-2">
                <code className="min-w-0 flex-1 rounded-lg border bg-muted/40 px-3 py-2 font-mono text-sm break-all">
                    {created.secret}
                </code>
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => copy(created.secret)}
                >
                    {copied ? <Check /> : <Copy />}
                    {copied ? 'Tersalin' : 'Salin'}
                </Button>
            </div>
            <p className="text-xs text-muted-foreground">
                API: <code className="font-mono">{apiUrl}</code>
            </p>
            <div>
                <Button type="button" variant="secondary" onClick={onDone}>
                    Sudah saya simpan
                </Button>
            </div>
        </SettingsCard>
    );
}
