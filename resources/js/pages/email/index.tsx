import { Form, Head, useHttp } from '@inertiajs/react';
import { ExternalLink, KeyRound, Mail, Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { AffixInput } from '@/components/affix-input';
import {
    CatchAllPanel,
    ForwardersPanel,
    type ForwarderRow,
    type MailDomainOption,
} from '@/components/email-forwarders';
import { GeneratedPasswordInput } from '@/components/generated-password-input';
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
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { formatMegabytes } from '@/lib/format';
import { openSignOnTab, postSignOnTicket } from '@/lib/sign-on';
import { destroy, store, update, webmail as openWebmail } from '@/routes/email';
import type { AccountLimits } from '@/types';

type MailboxRow = {
    id: number;
    address: string;
    domain: string;
    quota_mb: number | null;
    /** Whether its domain takes mail yet (the agent prepared it). */
    ready: boolean;
    created_at: string | null;
};

type Domain = MailDomainOption;

type Props = {
    mailboxes: MailboxRow[];
    forwarders: ForwarderRow[];
    domains: Domain[];
    max_quota_mb: number;
    max_destinations: number;
    limits: AccountLimits;
    server: string;
    webmail: {
        /** Whether Roundcube is set up on this server. */
        available: boolean;
        url: string | null;
        /** How long a webmail session opened from here lasts. */
        session_hours: number;
    };
};

type Action =
    | { type: 'edit'; mailbox: MailboxRow }
    | { type: 'delete'; mailbox: MailboxRow };

export default function EmailIndex({
    mailboxes,
    forwarders,
    domains,
    max_quota_mb,
    max_destinations,
    limits,
    server,
    webmail,
}: Props) {
    const [action, setAction] = useState<Action | null>(null);
    const [dialogOpen, setDialogOpen] = useState(false);

    const openDialog = (next: Action) => {
        setAction(next);
        setDialogOpen(true);
    };

    const closeDialog = () => setDialogOpen(false);

    const limit = limits.quotas.email_accounts.limit;
    const used = limits.quotas.email_accounts.used ?? mailboxes.length;
    const isAtLimit = limit !== null && used >= limit;

    return (
        <>
            <Head title="Email" />

            <PageHeader
                title="Email"
                description="Akun email untuk domain kamu, dibuka lewat webmail atau aplikasi email (IMAP/POP3 dan SMTP)."
            />

            <Panel
                title="Buat akun email"
                description="Akun pertama di sebuah domain sekaligus menyiapkan email domain itu: record MX, DKIM dan DMARC ditambahkan ke DNS-nya."
                action={
                    limit !== null && (
                        <span className="shrink-0 rounded-full bg-muted px-3 py-1 text-xs font-semibold whitespace-nowrap text-muted-foreground">
                            {used} dari {limit} akun email
                        </span>
                    )
                }
            >
                {domains.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Belum ada domain. Tambahkan domain di halaman Domains
                        dulu.
                    </p>
                ) : (
                    <CreateMailboxForm
                        domains={domains}
                        maxQuotaMb={max_quota_mb}
                        disabled={isAtLimit}
                    />
                )}
                {isAtLimit && (
                    <p className="mt-4 text-sm text-muted-foreground">
                        {limit === 0
                            ? 'Paket kamu tidak termasuk akun email.'
                            : `Paket kamu sudah mencapai batas ${limit} akun email.`}{' '}
                        Hapus akun yang tidak dipakai, atau minta paket yang
                        lebih besar ke penyedia hosting kamu.
                    </p>
                )}
            </Panel>

            <Panel
                title="Daftar akun email"
                description={`${mailboxes.length} akun email`}
                flush
            >
                {mailboxes.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 px-6 pt-4 pb-10 text-center">
                        <span className="flex size-14 items-center justify-center rounded-full bg-accent text-accent-foreground">
                            <Mail className="size-6" />
                        </span>
                        <p className="max-w-sm text-sm text-muted-foreground">
                            Belum ada akun email.
                        </p>
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className={tableStyles.table}>
                            <thead className={tableStyles.head}>
                                <tr>
                                    <th className={tableStyles.th}>Alamat</th>
                                    <th className={tableStyles.th}>Kuota</th>
                                    <th className={tableStyles.th}>
                                        <span className="sr-only">Aksi</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {mailboxes.map((mailbox) => (
                                    <tr
                                        key={mailbox.id}
                                        className={tableStyles.row}
                                    >
                                        <td className={tableStyles.td}>
                                            <span className="font-mono font-semibold break-all">
                                                {mailbox.address}
                                            </span>
                                            {!mailbox.ready && (
                                                <p className="mt-1 flex items-center gap-1.5 text-xs text-muted-foreground">
                                                    <Spinner /> Email domain
                                                    sedang disiapkan
                                                </p>
                                            )}
                                        </td>
                                        <td
                                            className={`${tableStyles.td} whitespace-nowrap text-muted-foreground`}
                                        >
                                            {mailbox.quota_mb === null
                                                ? 'Tanpa batas'
                                                : formatMegabytes(
                                                      mailbox.quota_mb,
                                                  )}
                                        </td>
                                        <td className={tableStyles.td}>
                                            <div className="flex items-center justify-end gap-1">
                                                {webmail.available && (
                                                    <WebmailButton
                                                        mailbox={mailbox}
                                                        sessionHours={
                                                            webmail.session_hours
                                                        }
                                                    />
                                                )}
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={`Ubah akun email ${mailbox.address}`}
                                                    onClick={() =>
                                                        openDialog({
                                                            type: 'edit',
                                                            mailbox,
                                                        })
                                                    }
                                                >
                                                    <Pencil />
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={`Hapus akun email ${mailbox.address}`}
                                                    onClick={() =>
                                                        openDialog({
                                                            type: 'delete',
                                                            mailbox,
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

            <ForwardersPanel
                forwarders={forwarders}
                domains={domains}
                maxDestinations={max_destinations}
                limit={limits.quotas.email_forwarders.limit}
                used={limits.quotas.email_forwarders.used ?? forwarders.length}
            />

            <CatchAllPanel domains={domains} />

            <Panel
                title="Cara terhubung"
                description="Buka webmail di browser, atau pakai aplikasi email seperti Thunderbird, Outlook, atau Mail di ponsel."
            >
                <dl className="grid gap-x-8 gap-y-3 text-sm sm:grid-cols-[auto_1fr]">
                    {webmail.available && webmail.url !== null && (
                        <>
                            <dt className="text-muted-foreground">Webmail</dt>
                            <dd>
                                <a
                                    href={webmail.url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="font-mono font-semibold break-all underline-offset-4 hover:underline"
                                >
                                    {webmail.url}
                                </a>{' '}
                                · masuk dengan alamat email dan password-nya,
                                atau lewat tombol Webmail di atas tanpa password
                            </dd>
                        </>
                    )}
                    <dt className="text-muted-foreground">Server masuk</dt>
                    <dd>
                        <span className="font-mono font-semibold break-all">
                            {server}
                        </span>{' '}
                        · IMAP port 993 (SSL/TLS) atau POP3 port 995
                    </dd>
                    <dt className="text-muted-foreground">Server keluar</dt>
                    <dd>
                        <span className="font-mono font-semibold break-all">
                            {server}
                        </span>{' '}
                        · SMTP port 465 (SSL/TLS) atau 587 (STARTTLS), dengan
                        login
                    </dd>
                    <dt className="text-muted-foreground">Login</dt>
                    <dd>Alamat email lengkap dan password-nya.</dd>
                </dl>
            </Panel>

            <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                <DialogContent className="rounded-2xl">
                    {action?.type === 'edit' && (
                        <EditMailboxForm
                            key={`edit-${action.mailbox.id}`}
                            mailbox={action.mailbox}
                            maxQuotaMb={max_quota_mb}
                            onSuccess={closeDialog}
                        />
                    )}
                    {action?.type === 'delete' && (
                        <Form
                            key={`delete-${action.mailbox.id}`}
                            {...destroy.form(action.mailbox.id)}
                            options={{ preserveScroll: true }}
                            onSuccess={closeDialog}
                            className="grid gap-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <DialogHeader>
                                        <DialogTitle>
                                            Hapus {action.mailbox.address}?
                                        </DialogTitle>
                                        <DialogDescription>
                                            Akun ini tidak bisa login lagi,
                                            email yang masuk ke alamat ini
                                            ditolak, dan semua email di dalamnya
                                            ikut dihapus.
                                        </DialogDescription>
                                    </DialogHeader>
                                    <InputError message={errors.mailbox} />
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
                                            Hapus akun email
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

function CreateMailboxForm({
    domains,
    maxQuotaMb,
    disabled,
}: {
    domains: Domain[];
    maxQuotaMb: number;
    disabled: boolean;
}) {
    const [localPart, setLocalPart] = useState('');
    const [domainId, setDomainId] = useState(String(domains[0].id));
    const [password, setPassword] = useState('');
    const [quota, setQuota] = useState('');

    const reset = () => {
        setLocalPart('');
        setPassword('');
        setQuota('');
    };

    return (
        <Form
            {...store.form()}
            options={{ preserveScroll: true }}
            onSuccess={reset}
            className="grid max-w-2xl gap-5"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="mail-local-part">Alamat email</Label>
                        <input
                            type="hidden"
                            name="domain_id"
                            value={domainId}
                        />
                        <div className="flex flex-col gap-2 sm:flex-row">
                            <div className="min-w-0 flex-1">
                                <AffixInput
                                    id="mail-local-part"
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
                                    placeholder="info"
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

                    <div className="grid gap-2">
                        <Label htmlFor="mail-password">Password</Label>
                        <GeneratedPasswordInput
                            id="mail-password"
                            value={password}
                            onChange={setPassword}
                            required
                            placeholder="Minimal 12 karakter"
                            disabled={disabled}
                            invalid={Boolean(errors.password)}
                        />
                        <InputError message={errors.password} />
                    </div>

                    <QuotaField
                        id="mail-quota"
                        value={quota}
                        onChange={setQuota}
                        maxQuotaMb={maxQuotaMb}
                        disabled={disabled}
                        error={errors.quota_mb}
                    />

                    <div>
                        <Button
                            type="submit"
                            size="lg"
                            disabled={processing || disabled}
                        >
                            {processing ? <Spinner /> : <Mail />}
                            Buat akun email
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function EditMailboxForm({
    mailbox,
    maxQuotaMb,
    onSuccess,
}: {
    mailbox: MailboxRow;
    maxQuotaMb: number;
    onSuccess: () => void;
}) {
    const [password, setPassword] = useState('');
    const [quota, setQuota] = useState(
        mailbox.quota_mb === null ? '' : String(mailbox.quota_mb),
    );

    return (
        <Form
            {...update.form(mailbox.id)}
            options={{ preserveScroll: true }}
            onSuccess={onSuccess}
            className="grid gap-5"
        >
            {({ processing, errors }) => (
                <>
                    <DialogHeader>
                        <DialogTitle>Ubah {mailbox.address}</DialogTitle>
                        <DialogDescription>
                            Kosongkan password untuk tetap memakai yang
                            sekarang.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="edit-mail-password">
                            Password baru
                        </Label>
                        <GeneratedPasswordInput
                            id="edit-mail-password"
                            value={password}
                            onChange={setPassword}
                            required={false}
                            placeholder="Tetap memakai password sekarang"
                            invalid={Boolean(errors.password)}
                        />
                        <InputError message={errors.password} />
                    </div>

                    <QuotaField
                        id="edit-mail-quota"
                        value={quota}
                        onChange={setQuota}
                        maxQuotaMb={maxQuotaMb}
                        error={errors.quota_mb}
                    />

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Batal
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={processing}>
                            {processing ? <Spinner /> : <KeyRound />}
                            Simpan
                        </Button>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}

type WebmailTicket = { url: string; ticket: string };

/**
 * Opens webmail in a new tab, signed in to the mailbox without its
 * password (see lib/sign-on).
 */
function WebmailButton({
    mailbox,
    sessionHours,
}: {
    mailbox: MailboxRow;
    sessionHours: number;
}) {
    const http = useHttp<Record<string, never>, WebmailTicket>({});

    const open = async () => {
        const tab = openSignOnTab('Webmail', 'Membuka webmail…');

        let response: WebmailTicket | undefined;

        try {
            response = await http.post(openWebmail.url(mailbox.id), {
                onError: (errors) => {
                    const message = Object.values(errors)[0];

                    if (message) {
                        toast.error(message);
                    }
                },
            });
        } catch {
            toast.error('Webmail tidak bisa dibuka. Coba lagi sebentar lagi.');
        }

        if (!response) {
            tab?.close();

            return;
        }

        postSignOnTicket(tab, response.url, '_rayxo_ticket', response.ticket);
    };

    return (
        <Button
            variant="outline"
            size="sm"
            disabled={!mailbox.ready || http.processing}
            onClick={open}
            aria-label={`Buka webmail ${mailbox.address}`}
            title={
                mailbox.ready
                    ? `Masuk otomatis tanpa password; berlaku ${sessionHours} jam`
                    : 'Email domain sedang disiapkan'
            }
        >
            {http.processing ? <Spinner /> : <ExternalLink />}
            Webmail
        </Button>
    );
}

/** How many megabytes a mailbox may hold; empty is no limit. */
function QuotaField({
    id,
    value,
    onChange,
    maxQuotaMb,
    disabled = false,
    error,
}: {
    id: string;
    value: string;
    onChange: (value: string) => void;
    maxQuotaMb: number;
    disabled?: boolean;
    error?: string;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>Kuota</Label>
            <div className="sm:max-w-56">
                <AffixInput
                    id={id}
                    name="quota_mb"
                    type="number"
                    inputMode="numeric"
                    min={1}
                    max={maxQuotaMb}
                    step={1}
                    suffix="MB"
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                    disabled={disabled}
                    placeholder="Tanpa batas"
                    invalid={Boolean(error)}
                />
            </div>
            <p className="text-xs text-muted-foreground">
                Kosongkan untuk tanpa batas; tetap terhitung di kuota disk akun.
            </p>
            <InputError message={error} />
        </div>
    );
}
