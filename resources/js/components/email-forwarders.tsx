import { Form } from '@inertiajs/react';
import { Cloud, Forward, Inbox, Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { AffixInput } from '@/components/affix-input';
import InputError from '@/components/input-error';
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
import { update as updateCatchAll } from '@/routes/email/catch-all';
import {
    destroy as destroyForwarder,
    store as storeForwarder,
    update as updateForwarder,
} from '@/routes/email/forwarders';

export type ForwarderRow = {
    id: number;
    address: string;
    domain: string;
    destinations: string[];
    /** Whether a mailbox has the same address and keeps a copy. */
    keeps_copy: boolean;
    /** Whether its domain takes mail yet (the agent prepared it). */
    ready: boolean;
    /** Whether another service takes its domain's mail, so it does nothing. */
    external: boolean;
};

export type MailDomainOption = {
    id: number;
    name: string;
    mail_status: 'pending' | 'active' | 'failed' | null;
    /** Null until the domain's first mailbox or forwarder. */
    mail_domain_id: number | null;
    catchall_target: string | null;
    /** Who takes the domain's mail: this server, or another service. */
    mail_mode: 'local' | 'external';
    mail_provider: 'google' | 'zoho' | 'other' | null;
};

const textareaClass =
    'min-h-28 w-full rounded-xl border border-input bg-card px-3.5 py-2.5 font-mono text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-destructive/20';

type ForwarderAction =
    | { type: 'edit'; forwarder: ForwarderRow }
    | { type: 'delete'; forwarder: ForwarderRow };

/**
 * Forwarders: addresses whose mail goes on to other addresses, here or
 * elsewhere. A forwarder may share its address with a mailbox, which then
 * keeps a copy.
 */
export function ForwardersPanel({
    forwarders,
    domains,
    selectedDomainId,
    maxDestinations,
    limit,
    used,
}: {
    forwarders: ForwarderRow[];
    domains: MailDomainOption[];
    /** The domain chosen at the top of the page, which the form follows. */
    selectedDomainId: number | null;
    maxDestinations: number;
    limit: number | null;
    used: number;
}) {
    const [action, setAction] = useState<ForwarderAction | null>(null);
    const [dialogOpen, setDialogOpen] = useState(false);
    const isAtLimit = limit !== null && used >= limit;

    const openDialog = (next: ForwarderAction) => {
        setAction(next);
        setDialogOpen(true);
    };

    return (
        <Panel
            title="Forwarder"
            description="Teruskan email yang masuk ke sebuah alamat ke alamat lain, di server ini atau di luar. Kalau alamatnya sama dengan akun email, akun itu tetap menerima salinannya."
            action={
                limit !== null && (
                    <span className="shrink-0 rounded-full bg-muted px-3 py-1 text-xs font-semibold whitespace-nowrap text-muted-foreground">
                        {used} dari {limit} forwarder
                    </span>
                )
            }
        >
            <div className="grid gap-6">
                {domains.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Belum ada domain. Tambahkan domain di halaman Domains
                        dulu.
                    </p>
                ) : (
                    <CreateForwarderForm
                        domains={domains}
                        selectedDomainId={selectedDomainId}
                        maxDestinations={maxDestinations}
                        disabled={isAtLimit}
                    />
                )}
                {isAtLimit && (
                    <p className="text-sm text-muted-foreground">
                        {limit === 0
                            ? 'Paket kamu tidak termasuk forwarder email.'
                            : `Paket kamu sudah mencapai batas ${limit} forwarder.`}
                    </p>
                )}

                {forwarders.length > 0 && (
                    <div className="-mx-6 -mb-6 overflow-x-auto border-t">
                        <table className={tableStyles.table}>
                            <thead className={tableStyles.head}>
                                <tr>
                                    <th className={tableStyles.th}>Alamat</th>
                                    <th className={tableStyles.th}>
                                        Diteruskan ke
                                    </th>
                                    <th className={tableStyles.th}>
                                        <span className="sr-only">Aksi</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {forwarders.map((forwarder) => (
                                    <tr
                                        key={forwarder.id}
                                        className={tableStyles.row}
                                    >
                                        <td className={tableStyles.td}>
                                            <span className="font-mono font-semibold break-all">
                                                {forwarder.address}
                                            </span>
                                            {forwarder.keeps_copy && (
                                                <p className="mt-1 flex items-center gap-1.5 text-xs text-muted-foreground">
                                                    <Inbox className="size-3.5" />
                                                    Akun email ini tetap
                                                    menerima salinan
                                                </p>
                                            )}
                                            {!forwarder.ready && (
                                                <p className="mt-1 flex items-center gap-1.5 text-xs text-muted-foreground">
                                                    <Spinner /> Email domain
                                                    sedang disiapkan
                                                </p>
                                            )}
                                            {forwarder.external && (
                                                <p className="mt-1 flex items-center gap-1.5 text-xs text-muted-foreground">
                                                    <Cloud className="size-3.5" />
                                                    Tidak berlaku: email domain
                                                    ini diterima layanan lain
                                                </p>
                                            )}
                                        </td>
                                        <td
                                            className={`${tableStyles.td} font-mono text-sm break-all text-muted-foreground`}
                                        >
                                            {forwarder.destinations.join(', ')}
                                        </td>
                                        <td className={tableStyles.td}>
                                            <div className="flex justify-end gap-1">
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={`Ubah forwarder ${forwarder.address}`}
                                                    onClick={() =>
                                                        openDialog({
                                                            type: 'edit',
                                                            forwarder,
                                                        })
                                                    }
                                                >
                                                    <Pencil />
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={`Hapus forwarder ${forwarder.address}`}
                                                    onClick={() =>
                                                        openDialog({
                                                            type: 'delete',
                                                            forwarder,
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
            </div>

            <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                <DialogContent className="rounded-2xl">
                    {action?.type === 'edit' && (
                        <EditForwarderForm
                            key={`edit-${action.forwarder.id}`}
                            forwarder={action.forwarder}
                            maxDestinations={maxDestinations}
                            onSuccess={() => setDialogOpen(false)}
                        />
                    )}
                    {action?.type === 'delete' && (
                        <Form
                            key={`delete-${action.forwarder.id}`}
                            {...destroyForwarder.form(action.forwarder.id)}
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
                                            Hapus forwarder{' '}
                                            {action.forwarder.address}?
                                        </DialogTitle>
                                        <DialogDescription>
                                            {action.forwarder.keeps_copy
                                                ? 'Email ke alamat ini tidak diteruskan lagi; akun emailnya tetap menerimanya.'
                                                : 'Email ke alamat ini tidak diteruskan lagi dan ditolak.'}
                                        </DialogDescription>
                                    </DialogHeader>
                                    <InputError message={errors.forwarder} />
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
                                            Hapus forwarder
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

function DestinationsField({
    id,
    defaultValue = '',
    maxDestinations,
    disabled = false,
    error,
}: {
    id: string;
    defaultValue?: string;
    maxDestinations: number;
    disabled?: boolean;
    error?: string;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>Diteruskan ke</Label>
            <textarea
                id={id}
                name="destinations"
                required
                disabled={disabled}
                defaultValue={defaultValue}
                spellCheck={false}
                autoComplete="off"
                placeholder={'nama@gmail.com\ntim@contoh.com'}
                className={textareaClass}
                aria-invalid={error ? true : undefined}
            />
            <p className="text-xs text-muted-foreground">
                Satu alamat per baris, paling banyak {maxDestinations}.
            </p>
            <InputError message={error} />
        </div>
    );
}

function CreateForwarderForm({
    domains,
    selectedDomainId,
    maxDestinations,
    disabled,
}: {
    domains: MailDomainOption[];
    selectedDomainId: number | null;
    maxDestinations: number;
    disabled: boolean;
}) {
    const [localPart, setLocalPart] = useState('');
    const [domainId, setDomainId] = useState(
        String(selectedDomainId ?? domains[0].id),
    );
    const [followedDomainId, setFollowedDomainId] = useState(selectedDomainId);
    const [formKey, setFormKey] = useState(0);

    if (selectedDomainId !== followedDomainId) {
        setFollowedDomainId(selectedDomainId);

        if (selectedDomainId !== null) {
            setDomainId(String(selectedDomainId));
        }
    }

    return (
        <Form
            key={formKey}
            {...storeForwarder.form()}
            options={{ preserveScroll: true, preserveState: true }}
            onSuccess={() => {
                setLocalPart('');
                setFormKey((key) => key + 1);
            }}
            className="grid max-w-2xl gap-5"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="forwarder-local-part">Alamat</Label>
                        <input
                            type="hidden"
                            name="domain_id"
                            value={domainId}
                        />
                        <div className="flex flex-col gap-2 sm:flex-row">
                            <div className="min-w-0 flex-1">
                                <AffixInput
                                    id="forwarder-local-part"
                                    name="local_part"
                                    suffix="@"
                                    value={localPart}
                                    onChange={(event) =>
                                        setLocalPart(
                                            event.target.value.toLowerCase(),
                                        )
                                    }
                                    required
                                    disabled={disabled}
                                    maxLength={64}
                                    placeholder="sales"
                                    invalid={Boolean(errors.local_part)}
                                />
                            </div>
                            <Select
                                value={domainId}
                                onValueChange={setDomainId}
                                disabled={disabled}
                            >
                                <SelectTrigger
                                    aria-label="Domain"
                                    aria-invalid={
                                        errors.domain_id ? true : undefined
                                    }
                                    className="w-full rounded-xl bg-card px-3.5 font-mono data-[size=default]:h-11 sm:w-auto sm:min-w-56"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent className="rounded-xl">
                                    {domains.map((domain) => (
                                        <SelectItem
                                            key={domain.id}
                                            value={String(domain.id)}
                                        >
                                            {domain.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <InputError
                            message={errors.local_part ?? errors.domain_id}
                        />
                    </div>

                    <DestinationsField
                        id="forwarder-destinations"
                        maxDestinations={maxDestinations}
                        disabled={disabled}
                        error={errors.destinations}
                    />

                    <div>
                        <Button
                            type="submit"
                            size="lg"
                            disabled={processing || disabled}
                        >
                            {processing ? <Spinner /> : <Forward />}
                            Buat forwarder
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function EditForwarderForm({
    forwarder,
    maxDestinations,
    onSuccess,
}: {
    forwarder: ForwarderRow;
    maxDestinations: number;
    onSuccess: () => void;
}) {
    return (
        <Form
            {...updateForwarder.form(forwarder.id)}
            options={{ preserveScroll: true, preserveState: true }}
            onSuccess={onSuccess}
            className="grid gap-5"
        >
            {({ processing, errors }) => (
                <>
                    <DialogHeader>
                        <DialogTitle>Ubah {forwarder.address}</DialogTitle>
                        <DialogDescription>
                            Email ke alamat ini diteruskan ke alamat-alamat di
                            bawah.
                        </DialogDescription>
                    </DialogHeader>

                    <DestinationsField
                        id="edit-forwarder-destinations"
                        defaultValue={forwarder.destinations.join('\n')}
                        maxDestinations={maxDestinations}
                        error={errors.destinations}
                    />

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Batal
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={processing}>
                            {processing ? <Spinner /> : <Forward />}
                            Simpan
                        </Button>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}

/**
 * The catch-all of each domain that takes mail: where mail for addresses
 * the domain does not have goes, or whether it is refused.
 */
export function CatchAllPanel({ domains }: { domains: MailDomainOption[] }) {
    const mailDomains = domains.filter(
        (domain) => domain.mail_domain_id !== null,
    );
    const [editing, setEditing] = useState<MailDomainOption | null>(null);
    const [dialogOpen, setDialogOpen] = useState(false);

    return (
        <Panel
            title="Catch-all"
            description="Email ke alamat yang tidak ada di sebuah domain biasanya ditolak. Catch-all meneruskannya ke satu alamat."
            flush
        >
            {mailDomains.length === 0 ? (
                <p className="px-6 pb-6 text-sm text-muted-foreground">
                    Buat akun email atau forwarder dulu; catch-all bisa diatur
                    untuk domain yang sudah memakai email.
                </p>
            ) : (
                <div className="overflow-x-auto">
                    <table className={tableStyles.table}>
                        <thead className={tableStyles.head}>
                            <tr>
                                <th className={tableStyles.th}>Domain</th>
                                <th className={tableStyles.th}>
                                    Email ke alamat lain
                                </th>
                                <th className={tableStyles.th}>
                                    <span className="sr-only">Aksi</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {mailDomains.map((domain) => (
                                <tr key={domain.id} className={tableStyles.row}>
                                    <td
                                        className={`${tableStyles.td} font-mono font-semibold break-all`}
                                    >
                                        {domain.name}
                                    </td>
                                    <td
                                        className={`${tableStyles.td} text-sm break-all text-muted-foreground`}
                                    >
                                        {domain.mail_mode === 'external' ? (
                                            'Tidak berlaku: email domain ini diterima layanan lain'
                                        ) : domain.catchall_target === null ? (
                                            'Ditolak'
                                        ) : (
                                            <>
                                                Diteruskan ke{' '}
                                                <span className="font-mono text-foreground">
                                                    {domain.catchall_target}
                                                </span>
                                            </>
                                        )}
                                    </td>
                                    <td className={tableStyles.td}>
                                        <div className="flex justify-end">
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                aria-label={`Ubah catch-all ${domain.name}`}
                                                onClick={() => {
                                                    setEditing(domain);
                                                    setDialogOpen(true);
                                                }}
                                            >
                                                <Pencil />
                                            </Button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                <DialogContent className="rounded-2xl">
                    {editing !== null && editing.mail_domain_id !== null && (
                        <Form
                            key={`catch-all-${editing.id}`}
                            {...updateCatchAll.form(editing.mail_domain_id)}
                            options={{
                                preserveScroll: true,
                                preserveState: true,
                            }}
                            onSuccess={() => setDialogOpen(false)}
                            className="grid gap-5"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <DialogHeader>
                                        <DialogTitle>
                                            Catch-all {editing.name}
                                        </DialogTitle>
                                        <DialogDescription>
                                            Email ke alamat yang tidak ada di
                                            domain ini diteruskan ke alamat
                                            berikut. Kosongkan untuk menolaknya.
                                        </DialogDescription>
                                    </DialogHeader>

                                    <div className="grid gap-2">
                                        <Label htmlFor="catchall-target">
                                            Teruskan ke
                                        </Label>
                                        <Input
                                            id="catchall-target"
                                            name="catchall_target"
                                            type="email"
                                            defaultValue={
                                                editing.catchall_target ?? ''
                                            }
                                            placeholder={`info@${editing.name}`}
                                            className="h-11 rounded-xl bg-card font-mono"
                                            aria-invalid={
                                                errors.catchall_target
                                                    ? true
                                                    : undefined
                                            }
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            Catch-all juga menerima spam ke
                                            alamat sembarang; pakai hanya kalau
                                            perlu.
                                        </p>
                                        <InputError
                                            message={errors.catchall_target}
                                        />
                                    </div>

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
                                            disabled={processing}
                                        >
                                            {processing ? (
                                                <Spinner />
                                            ) : (
                                                <Forward />
                                            )}
                                            Simpan
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
