import { router } from '@inertiajs/react';
import {
    DatabaseBackup,
    Download,
    RotateCcw,
    Server,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import { BackupChoice } from '@/components/backup-restore-dialog';
import InputError from '@/components/input-error';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatBytes, formatDateTime } from '@/lib/format';
import { restoreAll } from '@/routes/admin/backups';
import {
    destroy,
    download,
    settings as updateSettings,
    store,
} from '@/routes/admin/backups/system';

export type SystemBackup = {
    id: number;
    name: string;
    status: 'queued' | 'running' | 'completed' | 'failed';
    size: number | null;
    error: string | null;
    created_at: string | null;
};

export type SystemBackups = {
    settings: { enabled: boolean; keep: number };
    keep_max: number;
    backups: SystemBackup[];
};

/** The daily backups of the panel itself. */
export function SystemBackupPanel({ system }: { system: SystemBackups }) {
    const [form, setForm] = useState(system.settings);
    const [saving, setSaving] = useState(false);
    const [starting, setStarting] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [deleting, setDeleting] = useState<SystemBackup | null>(null);
    const dirty = JSON.stringify(form) !== JSON.stringify(system.settings);
    const busy = system.backups.some(
        (backup) => backup.status === 'queued' || backup.status === 'running',
    );

    const save = () => {
        setSaving(true);
        setErrors({});
        router.put(updateSettings.url(), form, {
            preserveScroll: true,
            onError: (failed) => setErrors(failed),
            onFinish: () => setSaving(false),
        });
    };

    const start = () => {
        setStarting(true);
        router.post(
            store.url(),
            {},
            {
                preserveScroll: true,
                onError: (failed) => setErrors(failed),
                onFinish: () => setStarting(false),
            },
        );
    };

    return (
        <>
            <Panel
                title="Backup sistem panel"
                description="Database panel (akun, paket, domain, DNS, email, pengaturan), konfigurasi /etc/rayxo beserta kunci yang membuka data terenkripsi panel, kunci DKIM, dan file yang diunggah (lampiran tiket, logo). Ukurannya kecil. Dengan backup ini panel bisa dipulihkan di server baru. Isinya rahasia: di server hanya root yang bisa membacanya, jadi simpan unduhannya di tempat aman."
            >
                <div className="grid gap-5">
                    <BackupChoice
                        id="system-enabled"
                        checked={form.enabled}
                        onChange={(enabled) => setForm({ ...form, enabled })}
                        label="Backup sistem setiap hari"
                        hint="Bawaan aktif. Dibuat sekali sehari oleh worker backup, terpisah dari backup akun."
                    />
                    <div className="grid gap-2 sm:max-w-xs">
                        <Label htmlFor="system-keep">
                            Jumlah backup sistem yang disimpan
                        </Label>
                        <Input
                            id="system-keep"
                            type="number"
                            min={1}
                            max={system.keep_max}
                            value={form.keep}
                            onChange={(event) =>
                                setForm({
                                    ...form,
                                    keep: Math.max(
                                        1,
                                        Math.min(
                                            system.keep_max,
                                            Number(event.target.value) || 1,
                                        ),
                                    ),
                                })
                            }
                        />
                        <InputError message={errors.keep} />
                    </div>
                    <InputError message={errors.system} />
                    <div className="flex flex-wrap gap-3">
                        <Button onClick={save} disabled={saving || !dirty}>
                            {saving && <Spinner />}
                            Simpan
                        </Button>
                        <Button
                            variant="outline"
                            onClick={start}
                            disabled={starting || busy}
                        >
                            {starting ? <Spinner /> : <DatabaseBackup />}
                            Backup sistem sekarang
                        </Button>
                    </div>
                </div>
            </Panel>

            {system.backups.length > 0 && (
                <Panel flush title="Backup sistem tersimpan">
                    <div className="overflow-x-auto">
                        <table className={tableStyles.table}>
                            <thead className={tableStyles.head}>
                                <tr>
                                    <th className={tableStyles.th}>File</th>
                                    <th className={tableStyles.th}>Dibuat</th>
                                    <th className={tableStyles.th}>Ukuran</th>
                                    <th className={tableStyles.th}>Status</th>
                                    <th className={tableStyles.th}>
                                        <span className="sr-only">Aksi</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {system.backups.map((backup) => (
                                    <tr
                                        key={backup.id}
                                        className={tableStyles.row}
                                    >
                                        <td
                                            className={`${tableStyles.td} font-mono text-xs`}
                                        >
                                            {backup.name}
                                        </td>
                                        <td
                                            className={`${tableStyles.td} whitespace-nowrap`}
                                        >
                                            {backup.created_at
                                                ? formatDateTime(
                                                      backup.created_at,
                                                  )
                                                : '–'}
                                        </td>
                                        <td
                                            className={`${tableStyles.td} whitespace-nowrap`}
                                        >
                                            {backup.size !== null
                                                ? formatBytes(backup.size)
                                                : '–'}
                                        </td>
                                        <td className={tableStyles.td}>
                                            <StatusBadge
                                                status={backup.status}
                                            />
                                            {backup.error && (
                                                <p className="mt-1 max-w-xs text-xs text-destructive">
                                                    {backup.error}
                                                </p>
                                            )}
                                        </td>
                                        <td className={tableStyles.td}>
                                            <div className="flex justify-end gap-1">
                                                {backup.status ===
                                                    'completed' && (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        asChild
                                                    >
                                                        <a
                                                            href={
                                                                download(
                                                                    backup.id,
                                                                ).url
                                                            }
                                                        >
                                                            <Download /> Unduh
                                                        </a>
                                                    </Button>
                                                )}
                                                {backup.status !==
                                                    'running' && (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        aria-label={`Hapus ${backup.name}`}
                                                        onClick={() =>
                                                            setDeleting(backup)
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
                </Panel>
            )}

            <Dialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
            >
                <DialogContent className="rounded-2xl">
                    <DialogHeader>
                        <DialogTitle>Hapus backup sistem?</DialogTitle>
                        <DialogDescription>
                            {deleting?.name} dihapus permanen dari disk server.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button variant="outline">Batal</Button>
                        </DialogClose>
                        <Button
                            variant="destructive"
                            onClick={() =>
                                deleting &&
                                router.delete(destroy.url(deleting.id), {
                                    preserveScroll: true,
                                    onFinish: () => setDeleting(null),
                                })
                            }
                        >
                            Hapus
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

/** One command, in a box it can be copied from. */
function Command({ children }: { children: string }) {
    return (
        <pre className="overflow-x-auto rounded-lg bg-muted px-3 py-2 font-mono text-xs">
            {children}
        </pre>
    );
}

/**
 * How the panel comes back on a new server, and restoring every account
 * from its newest backup once it did.
 */
export function ServerRestorePanel() {
    const [confirming, setConfirming] = useState(false);
    const [processing, setProcessing] = useState(false);

    const restore = () => {
        setProcessing(true);
        router.post(
            restoreAll.url(),
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setProcessing(false);
                    setConfirming(false);
                },
            },
        );
    };

    return (
        <Panel
            title="Pindah ke server baru"
            description="Kalau server ini rusak atau diganti, panel dan isinya bisa dipulihkan di server baru dari backup sistem dan backup akun. Unduh keduanya secara berkala dan simpan di luar server ini."
        >
            <ol className="grid list-decimal gap-4 pl-5 text-sm">
                <li className="grid gap-2">
                    Di server baru (Ubuntu 24.04 yang masih bersih), salin
                    backup sistem terbaru ke /root, lalu pasang RAYXO dengannya.
                    Panel kembali dengan akun admin, reseller, paket, dan
                    pengaturannya; akun hosting, domain, database, dan email
                    dibuat lagi (masih kosong).
                    <Command>
                        {
                            'bash setup.sh --restore-system /root/rayxo-system-NAMA.tar.gz'
                        }
                    </Command>
                </li>
                <li className="grid gap-2">
                    Salin backup akun ke folder backup server baru, lalu jadikan
                    milik root dan grup rayxo:
                    <Command>
                        {
                            'chown root:rayxo /var/lib/rayxo/backups/*.tar.gz\nchmod 640 /var/lib/rayxo/backups/*.tar.gz'
                        }
                    </Command>
                </li>
                <li className="grid gap-2">
                    Masuk ke panel di server baru dengan login admin lama, buka
                    menu Backup, lalu pulihkan semua akun: tiap akun aktif
                    mendapat file, email, dan isi database dari backup
                    terbarunya.
                    <div>
                        <Button
                            variant="outline"
                            onClick={() => setConfirming(true)}
                        >
                            <RotateCcw /> Pulihkan semua akun
                        </Button>
                    </div>
                </li>
            </ol>

            <Dialog open={confirming} onOpenChange={setConfirming}>
                <DialogContent className="rounded-2xl">
                    <DialogHeader>
                        <DialogTitle>Pulihkan semua akun?</DialogTitle>
                        <DialogDescription>
                            Tiap akun aktif yang backup-nya ada di server ini
                            dipulihkan dari backup terbarunya: file dan email di
                            backup menggantikan versi sekarang, dan isi database
                            diganti isi backup. Pakai ini setelah pindah ke
                            server baru, bukan untuk server yang sedang dipakai.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button variant="outline">Batal</Button>
                        </DialogClose>
                        <Button onClick={restore} disabled={processing}>
                            {processing ? <Spinner /> : <Server />}
                            Pulihkan semua
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </Panel>
    );
}
