import { Head, Link, router, usePoll } from '@inertiajs/react';
import { ArrowLeftRight, FileArchive, RefreshCw, Upload } from 'lucide-react';
import { useState } from 'react';
import { GeneratedPasswordInput } from '@/components/generated-password-input';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Panel, tableStyles } from '@/components/panel';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
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
import { formatBytes, formatDateTime } from '@/lib/format';
import { index as transfersIndex } from '@/routes/admin/transfers';
import { store } from '@/routes/admin/transfers/cpanel';

type CpanelImport = {
    id: number;
    file: string;
    username: string | null;
    status: string;
    error: string | null;
    notes: string[];
    created_at: string | null;
};

type BackupFile = {
    name: string;
    size: number;
    modified: number;
};

type Props = {
    imports: CpanelImport[];
    going: boolean;
    files: { items: BackupFile[]; error: string | null };
    packages: { id: number; name: string }[];
    folder: string;
};

const statusLabels: Record<string, string> = {
    queued: 'Antre',
    reading: 'Membaca backup',
    converting: 'Diubah ke format RAYXO',
    restoring: 'Isinya dipulihkan',
    completed: 'Selesai',
    failed: 'Gagal',
};

export default function CpanelImports({
    imports,
    going,
    files,
    packages,
    folder,
}: Props) {
    const [form, setForm] = useState({
        file: '',
        username: '',
        email: '',
        password: '',
        package: 'none',
    });
    const [processing, setProcessing] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    usePoll(5000, { only: ['imports', 'going'] }, { autoStart: going });

    const refresh = () =>
        router.reload({
            only: ['files'],
            onStart: () => setRefreshing(true),
            onFinish: () => setRefreshing(false),
        });

    const submit = () => {
        setProcessing(true);
        setErrors({});
        router.post(
            store.url(),
            {
                file: form.file,
                username: form.username || null,
                email: form.email || null,
                password: form.password,
                package_id:
                    form.package === 'none' ? null : Number(form.package),
            },
            {
                preserveScroll: true,
                onSuccess: () =>
                    setForm({ ...form, file: '', username: '', password: '' }),
                onError: (failed) => setErrors(failed),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <>
            <Head title="Impor dari cPanel" />

            <PageHeader
                title="Impor dari cPanel"
                description="Buat akun hosting di server ini dari backup akun cPanel."
                actions={
                    <Button variant="outline" asChild>
                        <Link href={transfersIndex()}>
                            <ArrowLeftRight />
                            Dari server RAYXO
                        </Link>
                    </Button>
                }
            />

            <Panel
                title="1. Siapkan backup cPanel"
                description="Backup lengkap akun cPanel berisi file situs, database, email, dan pengaturannya. Server cPanel tidak diubah."
            >
                <ol className="grid list-decimal gap-3 pl-5 text-sm">
                    <li>
                        Di server cPanel, buat backupnya. Sebagai root (WHM):{' '}
                        <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-xs">
                            /scripts/pkgacct username
                        </code>{' '}
                        menghasilkan{' '}
                        <code className="font-mono text-xs">
                            /home/cpmove-username.tar.gz
                        </code>
                        . Tanpa akses root, pemilik akun bisa membuatnya di
                        cPanel: Backup, lalu Download a Full Account Backup.
                    </li>
                    <li>
                        Salin file itu ke server ini, ke folder{' '}
                        <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-xs">
                            {folder}
                        </code>
                        , misalnya dengan{' '}
                        <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-xs break-all">
                            scp cpmove-username.tar.gz root@server-ini:{folder}/
                        </code>
                        . Selama impor, disk server ini perlu ruang sekitar tiga
                        kali ukuran file itu.
                    </li>
                    <li>Pilih filenya di bawah, lalu mulai impor.</li>
                </ol>
            </Panel>

            <Panel
                title="2. Impor akun"
                description="Ikut pindah: domain utama, addon domain, subdomain, parked domain, file situs, database beserta pengguna dan password-nya, akun email beserta password dan isinya, forwarder, dan cron job. Tidak ikut: password login cPanel (diganti password di bawah), SSL (dibuat lagi), record DNS buatan sendiri, akun FTP, dan SSH key."
            >
                <div className="grid gap-4 sm:max-w-xl">
                    <div className="grid gap-2">
                        <div className="flex items-center justify-between gap-2">
                            <Label htmlFor="cpanel-file">Backup cPanel</Label>
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={refresh}
                                disabled={refreshing}
                            >
                                {refreshing ? <Spinner /> : <RefreshCw />}
                                Muat ulang
                            </Button>
                        </div>
                        {files.items.length === 0 ? (
                            <p className="rounded-xl border border-dashed px-4 py-3 text-sm text-muted-foreground">
                                {files.error ??
                                    `Belum ada file baru di ${folder}.`}
                            </p>
                        ) : (
                            <Select
                                value={form.file}
                                onValueChange={(file) =>
                                    setForm({ ...form, file })
                                }
                            >
                                <SelectTrigger id="cpanel-file">
                                    <SelectValue placeholder="Pilih file" />
                                </SelectTrigger>
                                <SelectContent className="rounded-xl">
                                    {files.items.map((file) => (
                                        <SelectItem
                                            key={file.name}
                                            value={file.name}
                                        >
                                            {file.name} (
                                            {formatBytes(file.size)})
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        )}
                        <InputError message={errors.file} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="cpanel-username">Username</Label>
                        <Input
                            id="cpanel-username"
                            value={form.username}
                            onChange={(event) =>
                                setForm({
                                    ...form,
                                    username: event.target.value,
                                })
                            }
                            placeholder="Kosongkan untuk memakai username cPanel"
                            autoComplete="off"
                        />
                        <p className="text-xs text-muted-foreground">
                            Nama database dan penggunanya mengikuti username
                            ini. Kalau berbeda dari cPanel, pengaturan situs
                            yang menyebut nama database lama perlu disesuaikan.
                        </p>
                        <InputError message={errors.username} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="cpanel-email">Email</Label>
                        <Input
                            id="cpanel-email"
                            type="email"
                            value={form.email}
                            onChange={(event) =>
                                setForm({ ...form, email: event.target.value })
                            }
                            placeholder="Kosongkan untuk memakai email kontak di cPanel"
                        />
                        <InputError message={errors.email} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="cpanel-password">Password panel</Label>
                        <GeneratedPasswordInput
                            id="cpanel-password"
                            value={form.password}
                            onChange={(password) =>
                                setForm({ ...form, password })
                            }
                            required
                            placeholder="Password baru untuk login ke panel"
                            invalid={!!errors.password}
                        />
                        <InputError message={errors.password} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="cpanel-package">Paket</Label>
                        <Select
                            value={form.package}
                            onValueChange={(value) =>
                                setForm({ ...form, package: value })
                            }
                        >
                            <SelectTrigger id="cpanel-package">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent className="rounded-xl">
                                <SelectItem value="none">
                                    Tanpa paket
                                </SelectItem>
                                {packages.map((item) => (
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
                            Pastikan paketnya cukup untuk jumlah domain,
                            database, dan akun email di cPanel.
                        </p>
                        <InputError message={errors.package_id} />
                    </div>

                    <div>
                        <Button
                            onClick={submit}
                            disabled={
                                processing ||
                                form.file === '' ||
                                form.password === ''
                            }
                        >
                            {processing ? <Spinner /> : <Upload />}
                            Impor
                        </Button>
                    </div>
                </div>
            </Panel>

            <Panel
                flush
                title="Riwayat impor"
                description="Setelah akun dibuat di sini, domain, database, email, dan file-nya kembali di latar belakang; perkembangannya ada di menu Backup bagian Akun yang dibuat lagi dari backup. Setelah selesai, arahkan DNS domain-domainnya ke server ini."
            >
                {imports.length === 0 ? (
                    <div className="flex flex-col items-center gap-2 px-6 pt-4 pb-10 text-center text-sm text-muted-foreground">
                        <FileArchive className="size-8" />
                        Belum ada akun yang diimpor dari cPanel.
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className={tableStyles.table}>
                            <thead className={tableStyles.head}>
                                <tr>
                                    <th className={tableStyles.th}>Akun</th>
                                    <th className={tableStyles.th}>File</th>
                                    <th className={tableStyles.th}>Mulai</th>
                                    <th className={tableStyles.th}>Status</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {imports.map((item) => (
                                    <tr
                                        key={item.id}
                                        className={tableStyles.row}
                                    >
                                        <td
                                            className={`${tableStyles.td} font-medium`}
                                        >
                                            {item.username ?? '–'}
                                        </td>
                                        <td
                                            className={`${tableStyles.td} font-mono text-xs`}
                                        >
                                            {item.file}
                                        </td>
                                        <td
                                            className={`${tableStyles.td} whitespace-nowrap`}
                                        >
                                            {item.created_at
                                                ? formatDateTime(
                                                      item.created_at,
                                                  )
                                                : '–'}
                                        </td>
                                        <td className={tableStyles.td}>
                                            <StatusBadge
                                                status={item.status}
                                                label={
                                                    statusLabels[item.status]
                                                }
                                            />
                                            {item.error && (
                                                <p className="mt-1 max-w-md text-xs text-destructive">
                                                    {item.error}
                                                </p>
                                            )}
                                            {item.notes.length > 0 && (
                                                <details className="mt-1 max-w-md text-xs text-muted-foreground">
                                                    <summary className="cursor-pointer">
                                                        Catatan (
                                                        {item.notes.length})
                                                    </summary>
                                                    <ul className="mt-1 grid list-disc gap-1 pl-4">
                                                        {item.notes.map(
                                                            (note) => (
                                                                <li key={note}>
                                                                    {note}
                                                                </li>
                                                            ),
                                                        )}
                                                    </ul>
                                                </details>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Panel>
        </>
    );
}
