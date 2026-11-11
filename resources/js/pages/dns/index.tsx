import { Form, Head, router, usePoll } from '@inertiajs/react';
import { Network, Pencil, Plus, RotateCcw, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { AffixInput } from '@/components/affix-input';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Panel, tableStyles } from '@/components/panel';
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
import { index as dnsIndex, reset as resetZone } from '@/routes/dns';
import { destroy, store, update } from '@/routes/dns/records';

type RecordRow = {
    id: number;
    /** Relative to the zone; "@" is the zone itself. */
    name: string;
    type: string;
    content: string;
    ttl: number;
    priority: number | null;
};

type Zone = { id: number; name: string };

type Props = {
    zones: Zone[];
    zone: Zone | null;
    records: RecordRow[];
    nameservers: string[];
    types: string[];
    max_records: number;
    sync: { pending: boolean; error: string | null };
};

type Action =
    | { type: 'create' }
    | { type: 'edit'; record: RecordRow }
    | { type: 'delete'; record: RecordRow }
    | { type: 'reset' };

const placeholders: Record<string, string> = {
    A: '203.0.113.10',
    AAAA: '2001:db8::10',
    CNAME: 'contoh.com',
    MX: 'mail.contoh.com',
    TXT: 'v=spf1 a mx ~all',
    SRV: '5 5060 sip.contoh.com',
    CAA: '0 issue letsencrypt.org',
    NS: 'ns1.penyedia-lain.com',
};

const ttlLabels: Record<number, string> = {
    60: '1 menit',
    300: '5 menit',
    3600: '1 jam',
    14400: '4 jam',
    86400: '1 hari',
};

const withPriority = ['MX', 'SRV'];

function ttlLabel(ttl: number): string {
    return ttlLabels[ttl] ?? `${ttl} detik`;
}

export default function DnsIndex({
    zones,
    zone,
    records,
    nameservers,
    types,
    max_records,
    sync,
}: Props) {
    const [action, setAction] = useState<Action | null>(null);
    const [dialogOpen, setDialogOpen] = useState(false);

    const openDialog = (next: Action) => {
        setAction(next);
        setDialogOpen(true);
    };

    const closeDialog = () => setDialogOpen(false);

    // Refresh while the agent is still writing the zone.
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

    const chooseZone = (id: string) =>
        router.get(
            dnsIndex.url({ query: { zone: id } }),
            {},
            { preserveScroll: true },
        );

    const isFull = records.length >= max_records;

    return (
        <>
            <Head title="DNS" />

            <PageHeader
                title="DNS"
                description="Record DNS domain kamu di server ini: alamat website, email, dan verifikasi layanan lain."
                actions={
                    zone !== null && (
                        <Button
                            size="lg"
                            onClick={() => openDialog({ type: 'create' })}
                            disabled={isFull}
                        >
                            <Plus /> Tambah record
                        </Button>
                    )
                }
            />

            {sync.pending && (
                <p className="flex items-center gap-2 text-sm text-muted-foreground">
                    <Spinner /> Perubahan sedang diterapkan ke server DNS…
                </p>
            )}
            {sync.error !== null && (
                <div className="rounded-2xl border border-destructive/30 bg-destructive/5 px-5 py-4 text-sm">
                    <p className="font-semibold text-destructive-foreground">
                        Perubahan terakhir gagal diterapkan ke server DNS.
                    </p>
                    {sync.error !== '' && (
                        <p className="mt-1 break-all text-muted-foreground">
                            {sync.error}
                        </p>
                    )}
                </div>
            )}

            {zone === null ? (
                <Panel title="Belum ada zone DNS">
                    <div className="flex flex-col items-center gap-3 px-6 pt-4 pb-10 text-center">
                        <span className="flex size-14 items-center justify-center rounded-full bg-accent text-accent-foreground">
                            <Network className="size-6" />
                        </span>
                        <p className="max-w-sm text-sm text-muted-foreground">
                            Zone DNS dibuat otomatis saat kamu menambahkan
                            domain di halaman Domains.
                        </p>
                    </div>
                </Panel>
            ) : (
                <>
                    <Panel
                        title="Nameserver"
                        description={`Record di bawah hanya dipakai kalau nameserver ${zone.name} di registrar domain mengarah ke server ini.`}
                    >
                        <div className="grid gap-3 text-sm">
                            <ul className="grid gap-1 font-mono font-semibold">
                                {nameservers.map((nameserver) => (
                                    <li key={nameserver} className="break-all">
                                        {nameserver}
                                    </li>
                                ))}
                            </ul>
                            <p className="text-muted-foreground">
                                Kalau domain memakai DNS lain, misalnya
                                Cloudflare, atur record-nya di sana; record di
                                sini tidak dipakai.
                            </p>
                        </div>
                    </Panel>

                    <Panel
                        title={`Record ${zone.name}`}
                        description={`${records.length} dari ${max_records} record`}
                        action={
                            <div className="flex flex-wrap items-center gap-2">
                                {zones.length > 1 && (
                                    <Select
                                        value={String(zone.id)}
                                        onValueChange={chooseZone}
                                    >
                                        <SelectTrigger
                                            aria-label="Pilih zone"
                                            className="w-full rounded-xl bg-card px-3.5 font-mono sm:w-auto sm:min-w-56"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent className="rounded-xl">
                                            {zones.map((option) => (
                                                <SelectItem
                                                    key={option.id}
                                                    value={String(option.id)}
                                                >
                                                    {option.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                )}
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        openDialog({ type: 'reset' })
                                    }
                                >
                                    <RotateCcw /> Reset
                                </Button>
                            </div>
                        }
                        flush
                    >
                        {records.length === 0 ? (
                            <p className="px-6 pt-2 pb-8 text-sm text-muted-foreground">
                                Zone ini belum punya record.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className={tableStyles.table}>
                                    <thead className={tableStyles.head}>
                                        <tr>
                                            <th className={tableStyles.th}>
                                                Nama
                                            </th>
                                            <th className={tableStyles.th}>
                                                Jenis
                                            </th>
                                            <th className={tableStyles.th}>
                                                Isi
                                            </th>
                                            <th className={tableStyles.th}>
                                                TTL
                                            </th>
                                            <th className={tableStyles.th}>
                                                <span className="sr-only">
                                                    Aksi
                                                </span>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {records.map((record) => (
                                            <tr
                                                key={record.id}
                                                className={tableStyles.row}
                                            >
                                                <td
                                                    className={`${tableStyles.td} font-mono font-semibold break-all`}
                                                >
                                                    {record.name}
                                                </td>
                                                <td
                                                    className={`${tableStyles.td} font-mono`}
                                                >
                                                    {record.type}
                                                </td>
                                                <td
                                                    className={`${tableStyles.td} max-w-md font-mono text-xs break-all`}
                                                >
                                                    {record.priority !==
                                                        null && (
                                                        <span className="mr-1.5 text-muted-foreground">
                                                            {record.priority}
                                                        </span>
                                                    )}
                                                    {record.content}
                                                </td>
                                                <td
                                                    className={`${tableStyles.td} whitespace-nowrap text-muted-foreground`}
                                                >
                                                    {ttlLabel(record.ttl)}
                                                </td>
                                                <td className={tableStyles.td}>
                                                    <div className="flex justify-end gap-1">
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            aria-label={`Ubah record ${record.type} ${record.name}`}
                                                            onClick={() =>
                                                                openDialog({
                                                                    type: 'edit',
                                                                    record,
                                                                })
                                                            }
                                                        >
                                                            <Pencil />
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            aria-label={`Hapus record ${record.type} ${record.name}`}
                                                            onClick={() =>
                                                                openDialog({
                                                                    type: 'delete',
                                                                    record,
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
                </>
            )}

            {zone !== null && (
                <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                    <DialogContent className="rounded-2xl sm:max-w-xl">
                        {action?.type === 'create' && (
                            <RecordForm
                                key="create"
                                zone={zone}
                                types={types}
                                onSuccess={closeDialog}
                            />
                        )}
                        {action?.type === 'edit' && (
                            <RecordForm
                                key={`edit-${action.record.id}`}
                                zone={zone}
                                types={types}
                                record={action.record}
                                onSuccess={closeDialog}
                            />
                        )}
                        {action?.type === 'delete' && (
                            <ConfirmForm
                                key={`delete-${action.record.id}`}
                                form={destroy.form(action.record.id)}
                                title={`Hapus record ${action.record.type} ${action.record.name}?`}
                                description={`Record ini hilang dari zone ${zone.name}.`}
                                confirm="Hapus record"
                                onSuccess={closeDialog}
                            />
                        )}
                        {action?.type === 'reset' && (
                            <ConfirmForm
                                key="reset"
                                form={resetZone.form(zone.id)}
                                title={`Reset zone ${zone.name}?`}
                                description="Semua record diganti dengan record bawaan: alamat website, www, mail dan ftp, MX, SPF, dan record subdomain. Record yang kamu tambahkan sendiri hilang."
                                confirm="Reset zone"
                                onSuccess={closeDialog}
                            />
                        )}
                    </DialogContent>
                </Dialog>
            )}
        </>
    );
}

function RecordForm({
    zone,
    types,
    record,
    onSuccess,
}: {
    zone: Zone;
    types: string[];
    record?: RecordRow;
    onSuccess: () => void;
}) {
    const [type, setType] = useState(record?.type ?? 'A');
    const form = record ? update.form(record.id) : store.form(zone.id);

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
                            {record ? 'Ubah record' : 'Tambah record'}
                        </DialogTitle>
                        <DialogDescription>
                            Di zone{' '}
                            <span className="font-mono">{zone.name}</span>.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-5 sm:grid-cols-[10rem_1fr]">
                        <div className="grid gap-2">
                            <Label htmlFor="dns-type">Jenis</Label>
                            <input type="hidden" name="type" value={type} />
                            <Select value={type} onValueChange={setType}>
                                <SelectTrigger
                                    id="dns-type"
                                    aria-invalid={
                                        errors.type ? true : undefined
                                    }
                                    className="w-full rounded-xl bg-card px-3.5 font-mono data-[size=default]:h-11"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent className="rounded-xl">
                                    {types.map((option) => (
                                        <SelectItem key={option} value={option}>
                                            {option}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.type} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="dns-name">Nama</Label>
                            <AffixInput
                                id="dns-name"
                                name="name"
                                suffix={`.${zone.name}`}
                                defaultValue={record?.name ?? ''}
                                maxLength={253}
                                placeholder="@"
                                invalid={Boolean(errors.name)}
                            />
                            <p className="text-xs text-muted-foreground">
                                Kosongkan atau isi @ untuk {zone.name} sendiri.
                            </p>
                            <InputError message={errors.name} />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="dns-content">Isi</Label>
                        <Input
                            id="dns-content"
                            name="content"
                            defaultValue={record?.content ?? ''}
                            required
                            autoComplete="off"
                            spellCheck={false}
                            maxLength={4000}
                            placeholder={placeholders[type] ?? ''}
                            className="font-mono"
                            aria-invalid={errors.content ? true : undefined}
                        />
                        {type === 'TXT' && (
                            <p className="text-xs text-muted-foreground">
                                Tulis teksnya tanpa tanda kutip; teks panjang
                                dipecah otomatis.
                            </p>
                        )}
                        {type === 'SRV' && (
                            <p className="text-xs text-muted-foreground">
                                Bobot, port, dan target, dipisah spasi.
                            </p>
                        )}
                        <InputError message={errors.content} />
                    </div>

                    <div className="grid gap-5 sm:grid-cols-2">
                        {withPriority.includes(type) && (
                            <div className="grid gap-2">
                                <Label htmlFor="dns-priority">Prioritas</Label>
                                <Input
                                    id="dns-priority"
                                    name="priority"
                                    type="number"
                                    inputMode="numeric"
                                    min={0}
                                    max={65535}
                                    defaultValue={record?.priority ?? 10}
                                    required
                                    aria-invalid={
                                        errors.priority ? true : undefined
                                    }
                                />
                                <InputError message={errors.priority} />
                            </div>
                        )}
                        <div className="grid gap-2">
                            <Label htmlFor="dns-ttl">TTL (detik)</Label>
                            <Input
                                id="dns-ttl"
                                name="ttl"
                                type="number"
                                inputMode="numeric"
                                min={60}
                                max={604800}
                                defaultValue={record?.ttl ?? 3600}
                                required
                                aria-invalid={errors.ttl ? true : undefined}
                            />
                            <InputError message={errors.ttl} />
                        </div>
                    </div>

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Batal
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={processing}>
                            {processing ? <Spinner /> : <Network />}
                            {record ? 'Simpan' : 'Tambah record'}
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
    onSuccess,
}: {
    form: ReturnType<typeof destroy.form>;
    title: string;
    description: string;
    confirm: string;
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
                    <InputError message={errors.name} />
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
                            {confirm}
                        </Button>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}
