import { Form } from '@inertiajs/react';
import { Network, Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { AffixInput } from '@/components/affix-input';
import InputError from '@/components/input-error';
import { tableStyles } from '@/components/panel';
import { Button } from '@/components/ui/button';
import {
    DialogClose,
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
import type { RouteFormDefinition } from '@/wayfinder';

export type DnsRecordRow = {
    id: number;
    /** Relative to the zone; "@" is the zone itself. */
    name: string;
    type: string;
    content: string;
    ttl: number;
    priority: number | null;
};

const contentPlaceholders: Record<string, string> = {
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

export function ttlLabel(ttl: number): string {
    return ttlLabels[ttl] ?? `${ttl} detik`;
}

/**
 * The records of a zone (or the template), with edit and delete buttons
 * when the handlers are given.
 */
export function DnsRecordsTable({
    records,
    onEdit,
    onDelete,
}: {
    records: DnsRecordRow[];
    onEdit?: (record: DnsRecordRow) => void;
    onDelete?: (record: DnsRecordRow) => void;
}) {
    return (
        <div className="overflow-x-auto">
            <table className={tableStyles.table}>
                <thead className={tableStyles.head}>
                    <tr>
                        <th className={tableStyles.th}>Nama</th>
                        <th className={tableStyles.th}>Jenis</th>
                        <th className={tableStyles.th}>Isi</th>
                        <th className={tableStyles.th}>TTL</th>
                        <th className={tableStyles.th}>
                            <span className="sr-only">Aksi</span>
                        </th>
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {records.map((record) => (
                        <tr key={record.id} className={tableStyles.row}>
                            <td
                                className={`${tableStyles.td} font-mono font-semibold break-all`}
                            >
                                {record.name}
                            </td>
                            <td className={`${tableStyles.td} font-mono`}>
                                {record.type}
                            </td>
                            <td
                                className={`${tableStyles.td} max-w-md font-mono text-xs break-all`}
                            >
                                {record.priority !== null && (
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
                                    {onEdit && (
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            aria-label={`Ubah record ${record.type} ${record.name}`}
                                            onClick={() => onEdit(record)}
                                        >
                                            <Pencil />
                                        </Button>
                                    )}
                                    {onDelete && (
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            aria-label={`Hapus record ${record.type} ${record.name}`}
                                            onClick={() => onDelete(record)}
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
    );
}

/** Add or change one record; the dialog around it is the caller's. */
export function DnsRecordForm({
    form,
    title,
    description,
    suffix,
    nameHelp,
    contentHelp,
    types,
    record,
    onSuccess,
}: {
    form: RouteFormDefinition<'post'>;
    title: string;
    description: React.ReactNode;
    /** Shown after the name, such as ".example.com". */
    suffix: string;
    nameHelp: string;
    contentHelp?: string;
    types: string[];
    record?: DnsRecordRow;
    onSuccess: () => void;
}) {
    const [type, setType] = useState(record?.type ?? 'A');

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
                                suffix={suffix}
                                defaultValue={record?.name ?? ''}
                                maxLength={253}
                                placeholder="@"
                                invalid={Boolean(errors.name)}
                            />
                            <p className="text-xs text-muted-foreground">
                                {nameHelp}
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
                            placeholder={contentPlaceholders[type] ?? ''}
                            className="font-mono"
                            aria-invalid={errors.content ? true : undefined}
                        />
                        {contentHelp && (
                            <p className="text-xs text-muted-foreground">
                                {contentHelp}
                            </p>
                        )}
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

/** Confirm deleting a record or resetting a zone or the template. */
export function DnsConfirmForm({
    form,
    title,
    description,
    confirm,
    onSuccess,
}: {
    form: RouteFormDefinition<'post'>;
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

/** "Changes are being applied" or the last error, for one account's DNS. */
export function DnsSyncState({
    sync,
}: {
    sync: { pending: boolean; error: string | null };
}) {
    return (
        <>
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
        </>
    );
}
