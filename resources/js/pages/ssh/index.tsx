import { Form, Head, Link, router, usePoll } from '@inertiajs/react';
import { KeyRound, Plus, Power, Trash2 } from 'lucide-react';
import { useEffect } from 'react';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Panel } from '@/components/panel';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime } from '@/lib/format';
import { index as ftpIndex } from '@/routes/ftp';
import { update as updateAccess } from '@/routes/ssh/access';
import { destroy as destroyKey, store as storeKey } from '@/routes/ssh/keys';

type Access = {
    allowed: boolean;
    enabled: boolean;
    active: boolean;
    pending: boolean;
    error: string | null;
};

type Connection = {
    host: string;
    port: number;
    username: string;
    home: string;
    password_set: boolean;
};

type SshKeyRow = {
    id: number;
    name: string;
    type: string;
    fingerprint: string;
    created_at: string | null;
};

type Props = {
    access: Access;
    connection: Connection;
    keys: SshKeyRow[];
    max_keys: number;
};

const textareaClass =
    'min-h-28 w-full rounded-xl border border-input bg-card px-3.5 py-2.5 font-mono text-xs shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive aria-invalid:ring-destructive/20';

export default function SshIndex({
    access,
    connection,
    keys,
    max_keys,
}: Props) {
    // Refresh while the agent is still applying a change.
    const { start, stop } = usePoll(
        3000,
        { only: ['access'] },
        { autoStart: false },
    );

    useEffect(() => {
        if (access.pending) {
            start();
        } else {
            stop();
        }
    }, [access.pending, start, stop]);

    return (
        <>
            <Head title="SSH" />

            <PageHeader
                title="SSH"
                description="Akses shell ke akun kamu, di lingkungan terpisah yang hanya melihat program server dan home kamu, serta SSH key untuk login tanpa password."
            />

            <AccessPanel access={access} connection={connection} />

            <KeysPanel keys={keys} maxKeys={max_keys} />
        </>
    );
}

/** Whether the account has a shell, how to connect, and the switch. */
function AccessPanel({
    access,
    connection,
}: {
    access: Access;
    connection: Connection;
}) {
    const status = access.pending
        ? 'pending'
        : access.error !== null
          ? 'failed'
          : access.active
            ? 'active'
            : 'cancelled';

    return (
        <Panel
            title="Akses SSH"
            description="Login dengan username akun dan password SFTP, atau dengan salah satu SSH key di bawah."
            action={
                <StatusBadge
                    status={status}
                    label={
                        access.pending
                            ? 'Diterapkan'
                            : access.error !== null
                              ? 'Gagal'
                              : access.active
                                ? 'Aktif'
                                : 'Mati'
                    }
                />
            }
        >
            <div className="grid gap-6 lg:grid-cols-2">
                <dl className="grid content-start gap-x-8 gap-y-3 text-sm sm:grid-cols-[auto_1fr]">
                    <dt className="text-muted-foreground">Perintah</dt>
                    <dd className="font-mono font-semibold break-all">
                        ssh {connection.username}@{connection.host}
                    </dd>
                    <dt className="text-muted-foreground">Port</dt>
                    <dd className="font-mono font-semibold">
                        {connection.port}
                    </dd>
                    <dt className="text-muted-foreground">Home</dt>
                    <dd className="font-mono font-semibold">
                        {connection.home}
                    </dd>
                    <dt className="text-muted-foreground">Password</dt>
                    <dd>
                        {connection.password_set ? (
                            'Password SFTP kamu.'
                        ) : (
                            <>
                                Belum ada; atur password SFTP di{' '}
                                <Link
                                    href={ftpIndex()}
                                    className="font-semibold text-primary hover:underline"
                                >
                                    halaman FTP
                                </Link>
                                , atau tambahkan SSH key.
                            </>
                        )}
                    </dd>
                </dl>

                <div className="grid content-start gap-4">
                    {!access.allowed && (
                        <p className="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
                            {access.enabled
                                ? 'Paket kamu tidak lagi termasuk SSH, jadi shell kamu dimatikan. SSH key tetap bisa dipakai untuk SFTP.'
                                : 'Paket kamu tidak termasuk SSH. SSH key tetap bisa dipakai untuk login SFTP.'}
                        </p>
                    )}
                    {access.pending && (
                        <p className="flex items-center gap-2 text-sm text-muted-foreground">
                            <Spinner /> Perubahan sedang diterapkan ke server…
                        </p>
                    )}
                    {!access.pending && access.error !== null && (
                        <p className="text-sm text-destructive-foreground">
                            Perubahan terakhir gagal diterapkan
                            {access.error !== '' ? `: ${access.error}` : '.'}
                        </p>
                    )}

                    {(access.allowed || access.enabled) && (
                        <Form
                            {...updateAccess.form()}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing, errors }) => (
                                <div className="grid gap-2">
                                    <input
                                        type="hidden"
                                        name="enabled"
                                        value={access.enabled ? 0 : 1}
                                    />
                                    <div>
                                        <Button
                                            type="submit"
                                            variant={
                                                access.enabled
                                                    ? 'outline'
                                                    : 'default'
                                            }
                                            disabled={processing}
                                        >
                                            {processing ? (
                                                <Spinner />
                                            ) : (
                                                <Power />
                                            )}
                                            {access.enabled
                                                ? 'Matikan SSH'
                                                : 'Nyalakan SSH'}
                                        </Button>
                                    </div>
                                    <InputError message={errors.enabled} />
                                </div>
                            )}
                        </Form>
                    )}

                    <p className="text-xs text-muted-foreground">
                        Di dalam SSH kamu hanya melihat home kamu sendiri; akun
                        lain dan sebagian besar file server tidak terlihat.
                        Tersedia antara lain PHP, Composer, Git, dan klien
                        MySQL. Mematikan SSH menutup sesi yang sedang berjalan.
                    </p>
                </div>
            </div>
        </Panel>
    );
}

/** The public keys that log in to the account, for SSH and SFTP. */
function KeysPanel({ keys, maxKeys }: { keys: SshKeyRow[]; maxKeys: number }) {
    const isFull = keys.length >= maxKeys;

    return (
        <Panel
            title="SSH key"
            description={`Key publik yang boleh login ke akun kamu lewat SSH dan SFTP, tanpa password. Paling banyak ${maxKeys} key.`}
        >
            <div className="grid gap-5">
                {keys.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Belum ada key.
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {keys.map((key) => (
                            <li
                                key={key.id}
                                className="flex items-center justify-between gap-3 px-4 py-3"
                            >
                                <div className="flex min-w-0 items-start gap-3">
                                    <KeyRound className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                                    <div className="min-w-0">
                                        <p className="truncate font-semibold">
                                            {key.name}
                                        </p>
                                        <p className="truncate font-mono text-xs text-muted-foreground">
                                            {key.type} · {key.fingerprint}
                                        </p>
                                        {key.created_at && (
                                            <p className="text-xs text-muted-foreground">
                                                Ditambahkan{' '}
                                                {formatDateTime(key.created_at)}
                                            </p>
                                        )}
                                    </div>
                                </div>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    aria-label={`Hapus key ${key.name}`}
                                    onClick={() =>
                                        router.delete(destroyKey.url(key.id), {
                                            preserveScroll: true,
                                        })
                                    }
                                >
                                    <Trash2 />
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}

                {!isFull && (
                    <Form
                        {...storeKey.form()}
                        options={{ preserveScroll: true }}
                        resetOnSuccess
                        className="grid gap-3"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-1.5 sm:max-w-sm">
                                    <Label htmlFor="ssh-key-name">Nama</Label>
                                    <Input
                                        id="ssh-key-name"
                                        name="name"
                                        required
                                        maxLength={100}
                                        placeholder="Laptop kantor"
                                        aria-invalid={
                                            errors.name ? true : undefined
                                        }
                                    />
                                    <InputError message={errors.name} />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="ssh-public-key">
                                        Key publik
                                    </Label>
                                    <textarea
                                        id="ssh-public-key"
                                        name="public_key"
                                        required
                                        spellCheck={false}
                                        placeholder="ssh-ed25519 AAAA… kamu@laptop"
                                        className={textareaClass}
                                        aria-invalid={
                                            errors.public_key ? true : undefined
                                        }
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Isi file .pub, misalnya
                                        ~/.ssh/id_ed25519.pub. Belum punya? Buat
                                        dengan{' '}
                                        <code className="rounded bg-muted px-1.5 py-0.5 font-mono">
                                            ssh-keygen -t ed25519
                                        </code>
                                        . Jangan tempel private key.
                                    </p>
                                    <InputError message={errors.public_key} />
                                </div>
                                <div>
                                    <Button type="submit" disabled={processing}>
                                        {processing ? <Spinner /> : <Plus />}
                                        Tambahkan key
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                )}
            </div>
        </Panel>
    );
}
