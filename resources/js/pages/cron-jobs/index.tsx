import { Form, Head, router, usePoll } from '@inertiajs/react';
import { Clock, Mail, Pause, Pencil, Play, Plus, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
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
import { destroy, store, update } from '@/routes/cron-jobs';
import { update as updateEmail } from '@/routes/cron-jobs/email';
import type { AccountLimits } from '@/types';

type CronJobRow = {
    id: number;
    schedule: string;
    command: string;
    description: string | null;
    enabled: boolean;
};

type Props = {
    cron_jobs: CronJobRow[];
    sync: { pending: boolean; error: string | null };
    cron_email: string | null;
    home: string;
    limits: AccountLimits;
};

type Action =
    | { type: 'create' }
    | { type: 'edit'; job: CronJobRow }
    | { type: 'delete'; job: CronJobRow };

const presets = [
    { schedule: '* * * * *', label: 'Setiap menit' },
    { schedule: '*/5 * * * *', label: 'Setiap 5 menit' },
    { schedule: '*/15 * * * *', label: 'Setiap 15 menit' },
    { schedule: '0 * * * *', label: 'Setiap jam' },
    { schedule: '0 0 * * *', label: 'Setiap hari, jam 00:00' },
    { schedule: '0 0 * * 0', label: 'Setiap Minggu, jam 00:00' },
    { schedule: '0 0 1 * *', label: 'Setiap tanggal 1, jam 00:00' },
];

const specials: Record<string, string> = {
    '@hourly': 'Setiap jam',
    '@daily': 'Setiap hari, jam 00:00',
    '@midnight': 'Setiap hari, jam 00:00',
    '@weekly': 'Setiap Minggu, jam 00:00',
    '@monthly': 'Setiap tanggal 1, jam 00:00',
    '@yearly': 'Setiap 1 Januari, jam 00:00',
    '@annually': 'Setiap 1 Januari, jam 00:00',
};

/** A schedule in words, when it is a common one. */
function describeSchedule(schedule: string): string | null {
    return (
        presets.find((preset) => preset.schedule === schedule)?.label ??
        specials[schedule] ??
        null
    );
}

export default function CronJobsIndex({
    cron_jobs,
    sync,
    cron_email,
    home,
    limits,
}: Props) {
    const [action, setAction] = useState<Action | null>(null);
    const [dialogOpen, setDialogOpen] = useState(false);

    const openDialog = (next: Action) => {
        setAction(next);
        setDialogOpen(true);
    };

    const closeDialog = () => setDialogOpen(false);

    // Refresh while the agent is still writing the crontab.
    const { start, stop } = usePoll(
        3000,
        { only: ['sync'] },
        { autoStart: false },
    );

    useEffect(() => {
        if (sync.pending) {
            start();
        } else {
            stop();
        }
    }, [sync.pending, start, stop]);

    const toggle = (job: CronJobRow) => {
        router.put(
            update.url(job.id),
            {
                schedule: job.schedule,
                command: job.command,
                description: job.description ?? '',
                enabled: !job.enabled,
            },
            {
                preserveScroll: true,
                onError: (errors) => {
                    const message = Object.values(errors)[0];

                    if (message) {
                        toast.error(message);
                    }
                },
            },
        );
    };

    const limit = limits.quotas.cron_jobs.limit;
    const used = limits.quotas.cron_jobs.used ?? cron_jobs.length;
    const isAtLimit = limit !== null && used >= limit;

    return (
        <>
            <Head title="Cron Jobs" />

            <PageHeader
                title="Cron Jobs"
                description="Jalankan perintah otomatis sesuai jadwal, misalnya scheduler Laravel atau backup harian."
                actions={
                    <Button
                        size="lg"
                        onClick={() => openDialog({ type: 'create' })}
                        disabled={isAtLimit}
                    >
                        <Plus /> Tambah cron job
                    </Button>
                }
            />

            {sync.pending && (
                <p className="flex items-center gap-2 text-sm text-muted-foreground">
                    <Spinner /> Perubahan sedang diterapkan ke server…
                </p>
            )}
            {sync.error !== null && (
                <div className="rounded-2xl border border-destructive/30 bg-destructive/5 px-5 py-4 text-sm">
                    <p className="font-semibold text-destructive-foreground">
                        Perubahan terakhir gagal diterapkan ke server.
                    </p>
                    {sync.error !== '' && (
                        <p className="mt-1 text-muted-foreground">
                            {sync.error}
                        </p>
                    )}
                </div>
            )}

            <Panel
                title="Daftar cron job"
                description="Berjalan sebagai user akun kamu, di folder home, dengan zona waktu server."
                action={
                    limit !== null && (
                        <span className="shrink-0 rounded-full bg-muted px-3 py-1 text-xs font-semibold whitespace-nowrap text-muted-foreground">
                            {used} dari {limit} cron job
                        </span>
                    )
                }
                flush
            >
                {cron_jobs.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 px-6 pt-4 pb-10 text-center">
                        <span className="flex size-14 items-center justify-center rounded-full bg-accent text-accent-foreground">
                            <Clock className="size-6" />
                        </span>
                        <p className="max-w-sm text-sm text-muted-foreground">
                            {limit === 0
                                ? 'Paket kamu tidak termasuk cron job.'
                                : 'Belum ada cron job.'}
                        </p>
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className={tableStyles.table}>
                            <thead className={tableStyles.head}>
                                <tr>
                                    <th className={tableStyles.th}>Jadwal</th>
                                    <th className={tableStyles.th}>Perintah</th>
                                    <th className={tableStyles.th}>Status</th>
                                    <th className={tableStyles.th}>
                                        <span className="sr-only">Aksi</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {cron_jobs.map((job) => (
                                    <tr
                                        key={job.id}
                                        className={tableStyles.row}
                                    >
                                        <td
                                            className={`${tableStyles.td} whitespace-nowrap`}
                                        >
                                            <div className="font-semibold">
                                                {describeSchedule(
                                                    job.schedule,
                                                ) ?? 'Jadwal khusus'}
                                            </div>
                                            <code className="font-mono text-xs text-muted-foreground">
                                                {job.schedule}
                                            </code>
                                        </td>
                                        <td className={tableStyles.td}>
                                            {job.description && (
                                                <div className="font-semibold">
                                                    {job.description}
                                                </div>
                                            )}
                                            <code className="font-mono text-xs break-all text-muted-foreground">
                                                {job.command}
                                            </code>
                                        </td>
                                        <td className={tableStyles.td}>
                                            <StatusBadge
                                                status={
                                                    job.enabled
                                                        ? 'active'
                                                        : 'cancelled'
                                                }
                                                label={
                                                    job.enabled
                                                        ? 'Aktif'
                                                        : 'Dijeda'
                                                }
                                            />
                                        </td>
                                        <td className={tableStyles.td}>
                                            <div className="flex justify-end gap-1">
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={
                                                        job.enabled
                                                            ? 'Jeda cron job'
                                                            : 'Jalankan lagi cron job'
                                                    }
                                                    title={
                                                        job.enabled
                                                            ? 'Jeda'
                                                            : 'Jalankan lagi'
                                                    }
                                                    onClick={() => toggle(job)}
                                                >
                                                    {job.enabled ? (
                                                        <Pause />
                                                    ) : (
                                                        <Play />
                                                    )}
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label="Ubah cron job"
                                                    onClick={() =>
                                                        openDialog({
                                                            type: 'edit',
                                                            job,
                                                        })
                                                    }
                                                >
                                                    <Pencil />
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label="Hapus cron job"
                                                    onClick={() =>
                                                        openDialog({
                                                            type: 'delete',
                                                            job,
                                                        })
                                                    }
                                                >
                                                    <Trash2 />
                                                </Button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Panel>

            <CronEmailPanel email={cron_email} />

            <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                <DialogContent className="rounded-2xl sm:max-w-xl">
                    {action?.type === 'create' && (
                        <CronJobForm
                            key="create"
                            home={home}
                            onSuccess={closeDialog}
                        />
                    )}
                    {action?.type === 'edit' && (
                        <CronJobForm
                            key={`edit-${action.job.id}`}
                            job={action.job}
                            home={home}
                            onSuccess={closeDialog}
                        />
                    )}
                    {action?.type === 'delete' && (
                        <Form
                            key={`delete-${action.job.id}`}
                            {...destroy.form(action.job.id)}
                            options={{ preserveScroll: true }}
                            onSuccess={closeDialog}
                            className="grid gap-4"
                        >
                            {({ processing }) => (
                                <>
                                    <DialogHeader>
                                        <DialogTitle>
                                            Hapus cron job?
                                        </DialogTitle>
                                        <DialogDescription className="font-mono break-all">
                                            {action.job.command}
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
                                            Hapus cron job
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

function CronJobForm({
    job,
    home,
    onSuccess,
}: {
    job?: CronJobRow;
    home: string;
    onSuccess: () => void;
}) {
    const [schedule, setSchedule] = useState(job?.schedule ?? '*/5 * * * *');
    const preset = presets.some((option) => option.schedule === schedule)
        ? schedule
        : 'custom';
    const form = job ? update.form(job.id) : store.form();

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
                            {job ? 'Ubah cron job' : 'Tambah cron job'}
                        </DialogTitle>
                        <DialogDescription>
                            Perintah berjalan sebagai user akun kamu, di{' '}
                            <span className="font-mono">{home}</span>.
                        </DialogDescription>
                    </DialogHeader>

                    {job && (
                        <input
                            type="hidden"
                            name="enabled"
                            value={job.enabled ? '1' : '0'}
                        />
                    )}

                    <div className="grid gap-2">
                        <Label htmlFor="cron-schedule">Jadwal</Label>
                        <div className="flex flex-col gap-2 sm:flex-row">
                            <Select
                                value={preset}
                                onValueChange={(value) => {
                                    if (value !== 'custom') {
                                        setSchedule(value);
                                    }
                                }}
                            >
                                <SelectTrigger
                                    aria-label="Pilihan jadwal"
                                    className="w-full rounded-xl bg-card px-3.5 data-[size=default]:h-11 sm:w-60"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent className="rounded-xl">
                                    {presets.map((option) => (
                                        <SelectItem
                                            key={option.schedule}
                                            value={option.schedule}
                                        >
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                    <SelectItem value="custom">
                                        Jadwal khusus
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <Input
                                id="cron-schedule"
                                name="schedule"
                                value={schedule}
                                onChange={(event) =>
                                    setSchedule(event.target.value)
                                }
                                required
                                autoComplete="off"
                                spellCheck={false}
                                maxLength={100}
                                className="font-mono"
                                aria-invalid={
                                    errors.schedule ? true : undefined
                                }
                            />
                        </div>
                        <p className="text-xs text-muted-foreground">
                            Lima kolom: menit, jam, tanggal, bulan, hari (0 =
                            Minggu). Contoh: <code>30 2 * * *</code> = setiap
                            hari jam 02:30.
                        </p>
                        <InputError message={errors.schedule} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="cron-command">Perintah</Label>
                        <Input
                            id="cron-command"
                            name="command"
                            defaultValue={job?.command ?? ''}
                            required
                            autoComplete="off"
                            spellCheck={false}
                            maxLength={1024}
                            placeholder={`php ${home}/domains/contoh.com/public_html/artisan schedule:run`}
                            className="font-mono"
                            aria-invalid={errors.command ? true : undefined}
                        />
                        <p className="text-xs text-muted-foreground">
                            Tulis seperti di terminal (satu baris). Output
                            dikirim ke email output cron kalau diisi; untuk
                            menyimpannya ke file, tambahkan{' '}
                            <code>&gt;&gt; {home}/cron.log 2&gt;&amp;1</code>.
                        </p>
                        <InputError message={errors.command} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="cron-description">
                            Keterangan (opsional)
                        </Label>
                        <Input
                            id="cron-description"
                            name="description"
                            defaultValue={job?.description ?? ''}
                            maxLength={100}
                            placeholder="Scheduler toko online"
                        />
                        <InputError message={errors.description} />
                    </div>

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Batal
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={processing}>
                            {processing ? <Spinner /> : <Clock />}
                            {job ? 'Simpan' : 'Tambah cron job'}
                        </Button>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}

/**
 * The address cron mails the output of every job to; empty mails nothing.
 */
function CronEmailPanel({ email }: { email: string | null }) {
    return (
        <Panel
            title="Email output"
            description="Kirim output cron job ke email. Cron hanya mengirim email kalau job menghasilkan output."
        >
            <Form
                key={email ?? ''}
                {...updateEmail.form()}
                options={{ preserveScroll: true }}
                className="grid max-w-2xl gap-3"
            >
                {({ processing, errors }) => (
                    <>
                        <Label htmlFor="cron-email">Alamat email</Label>
                        <div className="flex flex-col gap-2 sm:flex-row">
                            <Input
                                id="cron-email"
                                name="cron_email"
                                type="email"
                                defaultValue={email ?? ''}
                                maxLength={254}
                                autoComplete="email"
                                placeholder="Kosongkan agar tidak dikirim"
                                className="sm:flex-1"
                                aria-invalid={
                                    errors.cron_email ? true : undefined
                                }
                            />
                            <Button type="submit" disabled={processing}>
                                {processing ? <Spinner /> : <Mail />}
                                Simpan
                            </Button>
                        </div>
                        <p className="text-xs text-muted-foreground">
                            Supaya satu job tidak mengirim email, tambahkan{' '}
                            <code>&gt;/dev/null 2&gt;&amp;1</code> di akhir
                            perintahnya.
                        </p>
                        <InputError message={errors.cron_email} />
                    </>
                )}
            </Form>
        </Panel>
    );
}
