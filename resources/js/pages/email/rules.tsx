import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft, Filter, MessageSquareReply, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { AffixInput } from '@/components/affix-input';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Panel, tableStyles } from '@/components/panel';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { index as emailIndex } from '@/routes/email';
import { update as updateAutoreply } from '@/routes/email/autoreply';
import {
    destroy as destroyFilter,
    store as storeFilter,
} from '@/routes/email/filters';

type Autoreply = {
    enabled: boolean;
    subject: string;
    body: string;
    starts_on: string | null;
    ends_on: string | null;
    interval_days: number;
};

type FilterRow = {
    id: number;
    field: 'from' | 'to' | 'subject';
    operator: 'contains' | 'is';
    value: string;
    action: 'move' | 'forward' | 'discard';
    target: string | null;
};

type Props = {
    mailbox: { id: number; address: string; ready: boolean };
    autoreply: Autoreply | null;
    filters: FilterRow[];
    max_filters: number;
};

const fieldLabels: Record<FilterRow['field'], string> = {
    from: 'Dari',
    to: 'Kepada atau Cc',
    subject: 'Subjek',
};

const operatorLabels: Record<FilterRow['operator'], string> = {
    contains: 'berisi',
    is: 'sama dengan',
};

const actionLabels: Record<FilterRow['action'], string> = {
    move: 'Pindahkan ke folder',
    forward: 'Teruskan salinan ke',
    discard: 'Hapus',
};

const textareaClass =
    'min-h-36 w-full rounded-xl border border-input bg-card px-3.5 py-2.5 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-destructive/20';

const triggerClass =
    'w-full rounded-xl bg-card px-3.5 data-[size=default]:h-11';

export default function EmailRules({
    mailbox,
    autoreply,
    filters,
    max_filters,
}: Props) {
    return (
        <>
            <Head title={`Filter ${mailbox.address}`} />

            <div>
                <Button asChild variant="ghost" size="sm">
                    <Link href={emailIndex()}>
                        <ArrowLeft /> Semua akun email
                    </Link>
                </Button>
            </div>

            <PageHeader
                title={<span className="break-all">{mailbox.address}</span>}
                description="Balasan otomatis dan filter untuk email yang masuk ke akun ini. Spam otomatis masuk ke folder Junk lebih dulu."
            />

            {!mailbox.ready && (
                <p className="flex items-center gap-2 text-sm text-muted-foreground">
                    <Spinner /> Email domain sedang disiapkan; pengaturan di
                    sini mulai berlaku setelahnya.
                </p>
            )}

            <AutoreplyPanel mailbox={mailbox} autoreply={autoreply} />

            <FiltersPanel
                mailbox={mailbox}
                filters={filters}
                maxFilters={max_filters}
            />
        </>
    );
}

function AutoreplyPanel({
    mailbox,
    autoreply,
}: {
    mailbox: Props['mailbox'];
    autoreply: Autoreply | null;
}) {
    const [enabled, setEnabled] = useState(autoreply?.enabled ?? false);

    return (
        <Panel
            title="Balasan otomatis"
            description="Pengirim mendapat balasan ini, misalnya saat kamu cuti. Satu pengirim dibalas paling sering sekali per jeda yang kamu pilih."
        >
            <Form
                {...updateAutoreply.form(mailbox.id)}
                options={{ preserveScroll: true, preserveState: true }}
                className="grid max-w-2xl gap-5"
            >
                {({ processing, errors }) => (
                    <>
                        <input
                            type="hidden"
                            name="enabled"
                            value={enabled ? '1' : '0'}
                        />
                        <div className="flex items-center gap-3">
                            <Checkbox
                                id="autoreply-enabled"
                                checked={enabled}
                                onCheckedChange={(checked) =>
                                    setEnabled(checked === true)
                                }
                            />
                            <Label htmlFor="autoreply-enabled">
                                Kirim balasan otomatis
                            </Label>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="autoreply-subject">Subjek</Label>
                            <Input
                                id="autoreply-subject"
                                name="subject"
                                defaultValue={autoreply?.subject ?? ''}
                                maxLength={150}
                                placeholder="Sedang cuti"
                                className="h-11 rounded-xl bg-card"
                                aria-invalid={errors.subject ? true : undefined}
                            />
                            <InputError message={errors.subject} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="autoreply-body">Isi balasan</Label>
                            <textarea
                                id="autoreply-body"
                                name="body"
                                defaultValue={autoreply?.body ?? ''}
                                maxLength={5000}
                                placeholder={
                                    'Terima kasih atas emailnya. Saya sedang cuti sampai tanggal 20 dan akan membalas setelah kembali.'
                                }
                                className={textareaClass}
                                aria-invalid={errors.body ? true : undefined}
                            />
                            <InputError message={errors.body} />
                        </div>

                        <div className="grid gap-4 sm:grid-cols-3">
                            <div className="grid gap-2">
                                <Label htmlFor="autoreply-starts">
                                    Mulai tanggal
                                </Label>
                                <Input
                                    id="autoreply-starts"
                                    name="starts_on"
                                    type="date"
                                    defaultValue={autoreply?.starts_on ?? ''}
                                    className="h-11 rounded-xl bg-card"
                                    aria-invalid={
                                        errors.starts_on ? true : undefined
                                    }
                                />
                                <InputError message={errors.starts_on} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="autoreply-ends">
                                    Sampai tanggal
                                </Label>
                                <Input
                                    id="autoreply-ends"
                                    name="ends_on"
                                    type="date"
                                    defaultValue={autoreply?.ends_on ?? ''}
                                    className="h-11 rounded-xl bg-card"
                                    aria-invalid={
                                        errors.ends_on ? true : undefined
                                    }
                                />
                                <InputError message={errors.ends_on} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="autoreply-interval">
                                    Jeda per pengirim
                                </Label>
                                <AffixInput
                                    id="autoreply-interval"
                                    name="interval_days"
                                    type="number"
                                    inputMode="numeric"
                                    min={1}
                                    max={30}
                                    step={1}
                                    suffix="hari"
                                    defaultValue={autoreply?.interval_days ?? 1}
                                    invalid={Boolean(errors.interval_days)}
                                />
                                <InputError message={errors.interval_days} />
                            </div>
                        </div>
                        <p className="-mt-2 text-xs text-muted-foreground">
                            Kosongkan tanggal untuk membalas sampai dimatikan.
                        </p>

                        <div>
                            <Button
                                type="submit"
                                size="lg"
                                disabled={processing}
                            >
                                {processing ? (
                                    <Spinner />
                                ) : (
                                    <MessageSquareReply />
                                )}
                                Simpan balasan otomatis
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </Panel>
    );
}

function FiltersPanel({
    mailbox,
    filters,
    maxFilters,
}: {
    mailbox: Props['mailbox'];
    filters: FilterRow[];
    maxFilters: number;
}) {
    const [field, setField] = useState<FilterRow['field']>('subject');
    const [operator, setOperator] = useState<FilterRow['operator']>('contains');
    const [action, setAction] = useState<FilterRow['action']>('move');
    const [formKey, setFormKey] = useState(0);
    const [deleting, setDeleting] = useState<FilterRow | null>(null);
    const [dialogOpen, setDialogOpen] = useState(false);
    const isFull = filters.length >= maxFilters;

    return (
        <Panel
            title="Filter"
            description="Dijalankan berurutan untuk setiap email yang masuk. Filter yang memindahkan atau menghapus email menghentikan filter sesudahnya."
        >
            <div className="grid gap-6">
                <Form
                    key={formKey}
                    {...storeFilter.form(mailbox.id)}
                    options={{ preserveScroll: true, preserveState: true }}
                    onSuccess={() => setFormKey((key) => key + 1)}
                    className="grid gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <input type="hidden" name="field" value={field} />
                            <input
                                type="hidden"
                                name="operator"
                                value={operator}
                            />
                            <input type="hidden" name="action" value={action} />

                            <div className="grid gap-2">
                                <Label htmlFor="filter-value">Jika</Label>
                                <div className="grid gap-2 sm:grid-cols-[auto_auto_1fr]">
                                    <Select
                                        value={field}
                                        onValueChange={(value) =>
                                            setField(
                                                value as FilterRow['field'],
                                            )
                                        }
                                        disabled={isFull}
                                    >
                                        <SelectTrigger
                                            aria-label="Bagian yang dicek"
                                            className={`${triggerClass} sm:min-w-40`}
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent className="rounded-xl">
                                            {Object.entries(fieldLabels).map(
                                                ([value, label]) => (
                                                    <SelectItem
                                                        key={value}
                                                        value={value}
                                                    >
                                                        {label}
                                                    </SelectItem>
                                                ),
                                            )}
                                        </SelectContent>
                                    </Select>
                                    <Select
                                        value={operator}
                                        onValueChange={(value) =>
                                            setOperator(
                                                value as FilterRow['operator'],
                                            )
                                        }
                                        disabled={isFull}
                                    >
                                        <SelectTrigger
                                            aria-label="Cara mencocokkan"
                                            className={`${triggerClass} sm:min-w-36`}
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent className="rounded-xl">
                                            {Object.entries(operatorLabels).map(
                                                ([value, label]) => (
                                                    <SelectItem
                                                        key={value}
                                                        value={value}
                                                    >
                                                        {label}
                                                    </SelectItem>
                                                ),
                                            )}
                                        </SelectContent>
                                    </Select>
                                    <Input
                                        id="filter-value"
                                        name="value"
                                        required
                                        maxLength={200}
                                        disabled={isFull}
                                        placeholder={
                                            field === 'subject'
                                                ? 'invoice'
                                                : 'tagihan@contoh.com'
                                        }
                                        className="h-11 rounded-xl bg-card"
                                        aria-invalid={
                                            errors.value ? true : undefined
                                        }
                                    />
                                </div>
                                <InputError message={errors.value} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="filter-target">Maka</Label>
                                <div className="grid gap-2 sm:grid-cols-[auto_1fr]">
                                    <Select
                                        value={action}
                                        onValueChange={(value) =>
                                            setAction(
                                                value as FilterRow['action'],
                                            )
                                        }
                                        disabled={isFull}
                                    >
                                        <SelectTrigger
                                            aria-label="Tindakan"
                                            className={`${triggerClass} sm:min-w-52`}
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent className="rounded-xl">
                                            {Object.entries(actionLabels).map(
                                                ([value, label]) => (
                                                    <SelectItem
                                                        key={value}
                                                        value={value}
                                                    >
                                                        {label}
                                                    </SelectItem>
                                                ),
                                            )}
                                        </SelectContent>
                                    </Select>
                                    {action !== 'discard' && (
                                        <Input
                                            id="filter-target"
                                            name="target"
                                            required
                                            disabled={isFull}
                                            type={
                                                action === 'forward'
                                                    ? 'email'
                                                    : 'text'
                                            }
                                            maxLength={
                                                action === 'forward' ? 254 : 64
                                            }
                                            placeholder={
                                                action === 'forward'
                                                    ? 'nama@gmail.com'
                                                    : 'Tagihan'
                                            }
                                            className="h-11 rounded-xl bg-card"
                                            aria-invalid={
                                                errors.target ? true : undefined
                                            }
                                        />
                                    )}
                                </div>
                                <InputError message={errors.target} />
                                {action === 'move' && (
                                    <p className="text-xs text-muted-foreground">
                                        Folder dibuat otomatis kalau belum ada.
                                    </p>
                                )}
                            </div>

                            <div>
                                <Button
                                    type="submit"
                                    size="lg"
                                    disabled={processing || isFull}
                                >
                                    {processing ? <Spinner /> : <Filter />}
                                    Tambah filter
                                </Button>
                            </div>
                            {isFull && (
                                <p className="text-sm text-muted-foreground">
                                    Akun email ini sudah punya {maxFilters}{' '}
                                    filter, batas maksimalnya.
                                </p>
                            )}
                        </>
                    )}
                </Form>

                {filters.length > 0 && (
                    <div className="-mx-6 -mb-6 overflow-x-auto border-t">
                        <table className={tableStyles.table}>
                            <thead className={tableStyles.head}>
                                <tr>
                                    <th className={tableStyles.th}>Jika</th>
                                    <th className={tableStyles.th}>Maka</th>
                                    <th className={tableStyles.th}>
                                        <span className="sr-only">Aksi</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {filters.map((filter) => (
                                    <tr
                                        key={filter.id}
                                        className={tableStyles.row}
                                    >
                                        <td
                                            className={`${tableStyles.td} text-sm`}
                                        >
                                            {fieldLabels[filter.field]}{' '}
                                            {operatorLabels[filter.operator]}{' '}
                                            <span className="font-mono font-semibold break-all">
                                                "{filter.value}"
                                            </span>
                                        </td>
                                        <td
                                            className={`${tableStyles.td} text-sm`}
                                        >
                                            {actionLabels[filter.action]}{' '}
                                            {filter.target !== null && (
                                                <span className="font-mono font-semibold break-all">
                                                    {filter.target}
                                                </span>
                                            )}
                                        </td>
                                        <td className={tableStyles.td}>
                                            <div className="flex justify-end">
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label="Hapus filter"
                                                    onClick={() => {
                                                        setDeleting(filter);
                                                        setDialogOpen(true);
                                                    }}
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
            </div>

            <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                <DialogContent className="rounded-2xl">
                    {deleting !== null && (
                        <Form
                            key={`delete-${deleting.id}`}
                            {...destroyFilter.form(deleting.id)}
                            options={{
                                preserveScroll: true,
                                preserveState: true,
                            }}
                            onSuccess={() => setDialogOpen(false)}
                            className="grid gap-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <DialogHeader>
                                        <DialogTitle>
                                            Hapus filter ini?
                                        </DialogTitle>
                                        <DialogDescription>
                                            {fieldLabels[deleting.field]}{' '}
                                            {operatorLabels[deleting.operator]}{' '}
                                            "{deleting.value}". Email yang masuk
                                            setelah ini tidak lagi disaring
                                            olehnya.
                                        </DialogDescription>
                                    </DialogHeader>
                                    <InputError message={errors.filter} />
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
                                            Hapus filter
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    )}
                </DialogContent>
            </Dialog>
        </Panel>
    );
}
