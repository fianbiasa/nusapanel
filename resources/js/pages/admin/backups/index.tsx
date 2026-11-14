import { Form, Head, router, usePoll } from '@inertiajs/react';
import {
    Archive,
    CalendarClock,
    Download,
    HardDrive,
    History,
    LoaderCircle,
    Play,
    RotateCcw,
    Search,
    Trash2,
    TriangleAlert,
} from 'lucide-react';
import { useState } from 'react';
import {
    BackupChoice,
    BackupRestoreDialog,
} from '@/components/backup-restore-dialog';
import type { InspectedBackup } from '@/components/backup-restore-dialog';
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
import { cn } from '@/lib/utils';
import {
    destroy,
    download,
    index,
    restore,
    run,
    schedule as updateSchedule,
} from '@/routes/admin/backups';
import type { Paginated } from '@/types';

type Frequency = 'daily' | 'weekly' | 'monthly';

type Schedule = {
    enabled: boolean;
    frequency: Frequency;
    hour: number;
    weekday: number;
    day: number;
    keep: number;
    files: boolean;
    email: boolean;
    databases: boolean;
};

type Run = {
    id: number;
    trigger: 'schedule' | 'manual';
    status: 'running' | 'completed' | 'skipped';
    accounts: number;
    succeeded: number;
    failed: number;
    message: string | null;
    started_at: string | null;
    finished_at: string | null;
};

type ServerBackup = {
    id: number;
    username: string;
    account_exists: boolean;
    name: string;
    status: 'queued' | 'running' | 'completed' | 'failed';
    files: boolean;
    email: boolean;
    databases: number;
    size: number | null;
    skipped: number;
    error: string | null;
    automatic: boolean;
    created_at: string | null;
};

type Props = {
    schedule: Schedule;
    timezone: string;
    next_run: string | null;
    space: { total: number; free: number; reserve: number } | null;
    estimate: { accounts: number; per_run: number; stored: number };
    runs: Run[];
    running: boolean;
    backups: Paginated<ServerBackup>;
    filters: { search: string };
    inspected?: InspectedBackup | null;
};

const frequencies: { value: Frequency; label: string }[] = [
    { value: 'daily', label: 'Setiap hari' },
    { value: 'weekly', label: 'Setiap minggu' },
    { value: 'monthly', label: 'Setiap bulan' },
];

const weekdays = [
    'Minggu',
    'Senin',
    'Selasa',
    'Rabu',
    'Kamis',
    'Jumat',
    'Sabtu',
];

const hours = Array.from({ length: 24 }, (_, hour) => hour);

const days = Array.from({ length: 28 }, (_, day) => day + 1);

function hourLabel(hour: number): string {
    return `${String(hour).padStart(2, '0')}:00`;
}

/** What a backup holds, in a few words. */
function contents(backup: ServerBackup): string {
    const parts = [
        backup.files && 'file',
        backup.email && 'email',
        backup.databases > 0 && `${backup.databases} database`,
    ].filter(Boolean);

    return parts.length > 0 ? parts.join(', ') : '–';
}

export default function ServerBackups({
    schedule,
    timezone,
    next_run,
    space,
    estimate,
    runs,
    running,
    backups,
    filters,
    inspected,
}: Props) {
    const [form, setForm] = useState<Schedule>(schedule);
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [confirmRun, setConfirmRun] = useState(false);
    const [starting, setStarting] = useState(false);
    const [restoring, setRestoring] = useState<ServerBackup | null>(null);
    const [deleting, setDeleting] = useState<ServerBackup | null>(null);

    usePoll(
        5000,
        { only: ['runs', 'running', 'backups', 'estimate', 'space'] },
        { autoStart: running },
    );

    const dirty = JSON.stringify(form) !== JSON.stringify(schedule);

    const save = () => {
        setSaving(true);
        setErrors({});
        router.put(updateSchedule.url(), form, {
            preserveScroll: true,
            onError: (failed) => setErrors(failed),
            onFinish: () => setSaving(false),
        });
    };

    const startRun = () => {
        setStarting(true);
        router.post(
            run.url(),
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setStarting(false);
                    setConfirmRun(false);
                },
            },
        );
    };

    const openRestore = (backup: ServerBackup) => {
        setRestoring(backup);
        router.reload({ only: ['inspected'], data: { restore: backup.id } });
    };

    // Each backup is a full copy; old ones go only after a new one is done,
    // so at the peak the disk holds one more than is kept.
    const kept = estimate.per_run * form.keep;
    const peak = estimate.per_run * (form.keep + 1);
    const room = space ? space.free + estimate.stored - space.reserve : null;
    const tight = room !== null && peak > room;

    return (
        <>
            <Head title="Backup" />

            <PageHeader
                title="Backup"
                description="Backup otomatis semua akun hosting, disimpan di disk server ini."
            />

            {running && (
                <div className="flex items-center gap-3 rounded-2xl border border-primary/20 bg-primary/5 px-5 py-4 text-sm">
                    <LoaderCircle className="size-4 animate-spin text-primary" />
                    Backup semua akun sedang berjalan, satu akun demi satu akun.
                    Halaman ini diperbarui sendiri.
                </div>
            )}

            <Panel
                title="Backup otomatis"
                description="Kalau aktif, panel mem-backup setiap akun hosting sesuai jadwal: file website dan home, database, dan email. Backup disimpan di disk server (/var/lib/rayxo/backups), di luar home akun, jadi tidak memakan kuota akun dan tidak bisa diubah pemilik akun. Akun dibackup satu per satu oleh worker tersendiri, jadi panel tetap bisa dipakai selama backup berjalan."
            >
                <div className="grid gap-6">
                    <BackupChoice
                        id="backup-enabled"
                        checked={form.enabled}
                        onChange={(enabled) => setForm({ ...form, enabled })}
                        label="Aktifkan backup otomatis"
                        hint="Bawaan mati. Mematikannya tidak menghapus backup yang sudah ada."
                    />

                    <div
                        className={cn(
                            'grid gap-6',
                            !form.enabled && 'pointer-events-none opacity-60',
                        )}
                        aria-disabled={!form.enabled}
                    >
                        <div className="grid gap-4 sm:grid-cols-3">
                            <div className="grid gap-2">
                                <Label htmlFor="backup-frequency">
                                    Seberapa sering
                                </Label>
                                <Select
                                    value={form.frequency}
                                    onValueChange={(frequency) =>
                                        setForm({
                                            ...form,
                                            frequency: frequency as Frequency,
                                        })
                                    }
                                >
                                    <SelectTrigger id="backup-frequency">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent className="rounded-xl">
                                        {frequencies.map((option) => (
                                            <SelectItem
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            {form.frequency === 'weekly' && (
                                <div className="grid gap-2">
                                    <Label htmlFor="backup-weekday">Hari</Label>
                                    <Select
                                        value={String(form.weekday)}
                                        onValueChange={(weekday) =>
                                            setForm({
                                                ...form,
                                                weekday: Number(weekday),
                                            })
                                        }
                                    >
                                        <SelectTrigger id="backup-weekday">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent className="rounded-xl">
                                            {weekdays.map((name, day) => (
                                                <SelectItem
                                                    key={name}
                                                    value={String(day)}
                                                >
                                                    {name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            )}

                            {form.frequency === 'monthly' && (
                                <div className="grid gap-2">
                                    <Label htmlFor="backup-day">Tanggal</Label>
                                    <Select
                                        value={String(form.day)}
                                        onValueChange={(day) =>
                                            setForm({
                                                ...form,
                                                day: Number(day),
                                            })
                                        }
                                    >
                                        <SelectTrigger id="backup-day">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent className="max-h-72 rounded-xl">
                                            {days.map((day) => (
                                                <SelectItem
                                                    key={day}
                                                    value={String(day)}
                                                >
                                                    {day}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            )}

                            <div className="grid gap-2">
                                <Label htmlFor="backup-hour">Jam</Label>
                                <Select
                                    value={String(form.hour)}
                                    onValueChange={(hour) =>
                                        setForm({ ...form, hour: Number(hour) })
                                    }
                                >
                                    <SelectTrigger id="backup-hour">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent className="max-h-72 rounded-xl">
                                        {hours.map((hour) => (
                                            <SelectItem
                                                key={hour}
                                                value={String(hour)}
                                            >
                                                {hourLabel(hour)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                        <p className="-mt-3 text-xs text-muted-foreground">
                            Jam mengikuti zona waktu server ({timezone}). Pilih
                            jam sepi, misalnya dini hari: backup membaca seluruh
                            disk akun dan memakai CPU untuk mengompres.
                        </p>

                        <div className="grid gap-2 sm:max-w-xs">
                            <Label htmlFor="backup-keep">
                                Jumlah backup yang disimpan per akun
                            </Label>
                            <Input
                                id="backup-keep"
                                type="number"
                                min={1}
                                max={30}
                                value={form.keep}
                                onChange={(event) =>
                                    setForm({
                                        ...form,
                                        keep: Math.max(
                                            1,
                                            Math.min(
                                                30,
                                                Number(event.target.value) || 1,
                                            ),
                                        ),
                                    })
                                }
                            />
                            <p className="text-xs text-muted-foreground">
                                Rotasi: setelah backup baru selesai, backup
                                paling lama di luar jumlah ini dihapus otomatis.
                                Misalnya 3 dengan jadwal harian berarti backup 3
                                hari terakhir.
                            </p>
                            <InputError message={errors.keep} />
                        </div>

                        <div className="grid gap-4">
                            <p className="text-sm font-medium">Isi backup</p>
                            <BackupChoice
                                id="backup-files"
                                checked={form.files}
                                onChange={(files) =>
                                    setForm({ ...form, files })
                                }
                                label="File website dan home"
                                hint="Folder domains dan file lain di home, tanpa folder backups, trash, dan tmp."
                            />
                            <BackupChoice
                                id="backup-databases"
                                checked={form.databases}
                                onChange={(databases) =>
                                    setForm({ ...form, databases })
                                }
                                label="Database"
                                hint="Semua database MariaDB akun beserta user dan password-nya."
                            />
                            <BackupChoice
                                id="backup-email"
                                checked={form.email}
                                onChange={(email) =>
                                    setForm({ ...form, email })
                                }
                                label="Email"
                                hint="Isi semua akun email beserta akun dan password-nya."
                            />
                            <InputError message={errors.items} />
                        </div>
                    </div>

                    <div
                        className={cn(
                            'grid gap-2 rounded-xl border px-4 py-3 text-sm',
                            tight
                                ? 'border-destructive/30 bg-destructive/5'
                                : 'border-warning/30 bg-warning/5',
                        )}
                    >
                        <p className="flex items-center gap-2 font-semibold">
                            <HardDrive className="size-4" />
                            Dampak ke disk server
                        </p>
                        <p className="text-muted-foreground">
                            Setiap backup adalah salinan penuh, jadi disk server
                            membengkak sekitar ukuran semua akun × jumlah backup
                            yang disimpan. Satu putaran untuk{' '}
                            {estimate.accounts} akun kira-kira{' '}
                            <strong className="text-foreground">
                                {formatBytes(estimate.per_run)}
                            </strong>
                            ; dengan {form.keep} backup disimpan, sekitar{' '}
                            <strong className="text-foreground">
                                {formatBytes(kept)}
                            </strong>
                            , ditambah satu putaran lagi sementara backup baru
                            dibuat sebelum yang lama dihapus (
                            {formatBytes(peak)} di puncaknya). Perkiraan memakai
                            ukuran backup terakhir tiap akun, atau pemakaian
                            disk dan database-nya kalau belum pernah dibackup.
                        </p>
                        {space && (
                            <p className="text-muted-foreground">
                                Ruang kosong sekarang {formatBytes(space.free)}{' '}
                                dari {formatBytes(space.total)}; backup yang
                                tersimpan memakai {formatBytes(estimate.stored)}
                                . RAYXO selalu menyisakan{' '}
                                {formatBytes(space.reserve)} untuk website,
                                email, dan database: sebelum setiap backup ruang
                                kosong diperiksa, dan kalau tidak cukup backup
                                dilewati dan admin dikabari lewat notifikasi.
                            </p>
                        )}
                        {tight && (
                            <p className="flex items-start gap-2 font-medium text-destructive">
                                <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                                Ruang disk kemungkinan tidak cukup untuk{' '}
                                {form.keep} backup per akun. Kurangi jumlah yang
                                disimpan, atau tambah disk server.
                            </p>
                        )}
                    </div>

                    <InputError message={errors.backup} />

                    <div className="flex flex-wrap items-center gap-3">
                        <Button onClick={save} disabled={saving || !dirty}>
                            {saving && <Spinner />}
                            Simpan
                        </Button>
                        <Button
                            variant="outline"
                            disabled={running || estimate.accounts === 0}
                            onClick={() => setConfirmRun(true)}
                        >
                            <Play /> Backup semua akun sekarang
                        </Button>
                        {schedule.enabled && next_run && (
                            <span className="flex items-center gap-2 text-sm text-muted-foreground">
                                <CalendarClock className="size-4" />
                                Backup berikutnya {next_run} ({timezone})
                            </span>
                        )}
                    </div>
                </div>
            </Panel>

            <Panel
                flush
                title="Riwayat backup otomatis"
                description="Lima putaran terakhir, dari jadwal atau dijalankan admin."
            >
                {runs.length === 0 ? (
                    <div className="flex flex-col items-center gap-2 px-6 pt-4 pb-10 text-center text-sm text-muted-foreground">
                        <History className="size-8" />
                        Belum pernah berjalan.
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className={tableStyles.table}>
                            <thead className={tableStyles.head}>
                                <tr>
                                    <th className={tableStyles.th}>Mulai</th>
                                    <th className={tableStyles.th}>Dari</th>
                                    <th className={tableStyles.th}>Status</th>
                                    <th className={tableStyles.th}>Hasil</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {runs.map((item) => (
                                    <tr
                                        key={item.id}
                                        className={tableStyles.row}
                                    >
                                        <td
                                            className={`${tableStyles.td} whitespace-nowrap`}
                                        >
                                            {item.started_at
                                                ? formatDateTime(
                                                      item.started_at,
                                                  )
                                                : '–'}
                                        </td>
                                        <td className={tableStyles.td}>
                                            {item.trigger === 'schedule'
                                                ? 'Jadwal'
                                                : 'Admin'}
                                        </td>
                                        <td className={tableStyles.td}>
                                            <StatusBadge status={item.status} />
                                        </td>
                                        <td
                                            className={`${tableStyles.td} text-muted-foreground`}
                                        >
                                            {item.status === 'skipped'
                                                ? item.message
                                                : `${item.succeeded} dari ${item.accounts} akun berhasil${item.failed > 0 ? `, ${item.failed} gagal` : ''}`}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Panel>

            <Panel
                flush
                title="Backup tersimpan"
                description="Unduh untuk menyimpannya di luar server. Pulihkan mengembalikan isi backup ke akunnya; backup akun yang sudah dihapus tetap ada sampai dihapus di sini."
                action={
                    <Form
                        {...index.form()}
                        options={{ preserveState: true, replace: true }}
                        className="flex w-full max-w-sm items-center gap-2"
                    >
                        <div className="relative flex-1">
                            <Search className="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                type="search"
                                name="search"
                                defaultValue={filters.search}
                                placeholder="Cari username"
                                aria-label="Cari backup"
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
                {backups.data.length === 0 ? (
                    <div className="flex flex-col items-center gap-2 px-6 pt-4 pb-10 text-center text-sm text-muted-foreground">
                        <Archive className="size-8" />
                        {filters.search
                            ? `Tidak ada backup akun yang cocok dengan "${filters.search}".`
                            : 'Belum ada backup.'}
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className={tableStyles.table}>
                            <thead className={tableStyles.head}>
                                <tr>
                                    <th className={tableStyles.th}>Akun</th>
                                    <th className={tableStyles.th}>Dibuat</th>
                                    <th className={tableStyles.th}>Isi</th>
                                    <th className={tableStyles.th}>Ukuran</th>
                                    <th className={tableStyles.th}>Status</th>
                                    <th className={tableStyles.th}>
                                        <span className="sr-only">Aksi</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {backups.data.map((backup) => (
                                    <tr
                                        key={backup.id}
                                        className={tableStyles.row}
                                    >
                                        <td className={tableStyles.td}>
                                            <div className="font-medium">
                                                {backup.username}
                                            </div>
                                            {!backup.account_exists && (
                                                <div className="text-xs text-muted-foreground">
                                                    Akun sudah dihapus
                                                </div>
                                            )}
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
                                        <td className={tableStyles.td}>
                                            {contents(backup)}
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
                                            {backup.skipped > 0 && (
                                                <p className="mt-1 max-w-xs text-xs text-muted-foreground">
                                                    {backup.skipped} file tidak
                                                    terbaca dan dilewati.
                                                </p>
                                            )}
                                        </td>
                                        <td className={tableStyles.td}>
                                            <div className="flex justify-end gap-1">
                                                {backup.status ===
                                                    'completed' && (
                                                    <>
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
                                                                <Download />{' '}
                                                                Unduh
                                                            </a>
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            disabled={
                                                                !backup.account_exists
                                                            }
                                                            onClick={() =>
                                                                openRestore(
                                                                    backup,
                                                                )
                                                            }
                                                        >
                                                            <RotateCcw />{' '}
                                                            Pulihkan
                                                        </Button>
                                                    </>
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
                )}
                <div className="px-6 py-4">
                    <Pagination page={backups} />
                </div>
            </Panel>

            <BackupRestoreDialog
                name={restoring?.name ?? null}
                inspected={
                    inspected && inspected.name === restoring?.name
                        ? inspected
                        : null
                }
                action={restoring ? restore.url(restoring.id) : null}
                onClose={() => setRestoring(null)}
            >
                <p className="rounded-xl border bg-muted/40 px-4 py-3 text-sm">
                    Dipulihkan ke akun <strong>{restoring?.username}</strong>.
                    Restore berjalan di worker backup; kalau ada backup semua
                    akun yang sedang berjalan, restore menunggu giliran.
                </p>
            </BackupRestoreDialog>

            <Dialog open={confirmRun} onOpenChange={setConfirmRun}>
                <DialogContent className="rounded-2xl">
                    <DialogHeader>
                        <DialogTitle>Backup semua akun sekarang?</DialogTitle>
                        <DialogDescription>
                            {estimate.accounts} akun dibackup satu per satu
                            dengan isi dan rotasi seperti pengaturan yang
                            tersimpan. Butuh kira-kira{' '}
                            {formatBytes(estimate.per_run)} ruang disk.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button variant="outline">Batal</Button>
                        </DialogClose>
                        <Button onClick={startRun} disabled={starting}>
                            {starting ? <Spinner /> : <Play />}
                            Mulai backup
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
            >
                <DialogContent className="rounded-2xl">
                    <DialogHeader>
                        <DialogTitle>Hapus backup?</DialogTitle>
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
