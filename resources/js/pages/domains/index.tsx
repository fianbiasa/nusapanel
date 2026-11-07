import { Form, Head, router, usePoll } from '@inertiajs/react';
import {
    ExternalLink,
    Globe,
    Lock,
    LockOpen,
    MoreHorizontal,
    Plus,
    RotateCw,
    Trash2,
    X,
} from 'lucide-react';
import type { ReactNode } from 'react';
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
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { formatDate, formatDateTime } from '@/lib/format';
import { destroy, store } from '@/routes/domains';
import { update as updateForceHttps } from '@/routes/domains/force-https';
import { destroy as destroySsl, store as storeSsl } from '@/routes/domains/ssl';

type DomainStatus = 'pending' | 'active' | 'failed' | 'deleting';

type SslStatus = 'pending' | 'active' | 'failed' | 'removing';

type DomainRow = {
    id: number;
    name: string;
    display_name: string;
    php_version: string;
    is_primary: boolean;
    status: DomainStatus;
    ssl_status: SslStatus | null;
    ssl_expires_at: string | null;
    ssl_error: string | null;
    force_https: boolean;
    created_at: string | null;
};

type DomainAction = {
    type: 'request-ssl' | 'remove-ssl' | 'delete';
    domain: DomainRow;
};

type Props = {
    domains: DomainRow[];
    public_ip: string | null;
};

const busyStatuses: DomainStatus[] = ['pending', 'deleting'];

const busySslStatuses: SslStatus[] = ['pending', 'removing'];

const deletableStatuses: DomainStatus[] = ['active', 'failed'];

function isSslBusy(domain: DomainRow): boolean {
    return (
        domain.ssl_status !== null &&
        busySslStatuses.includes(domain.ssl_status)
    );
}

/** Show the first error of a request made without a form, as a toast. */
function toastFirstError(errors: Record<string, string>) {
    const message = Object.values(errors)[0];

    if (message) {
        toast.error(message);
    }
}

export default function DomainsIndex({ domains, public_ip }: Props) {
    const [action, setAction] = useState<DomainAction | null>(null);
    const [dialogOpen, setDialogOpen] = useState(false);

    // Refresh the list while the agent is still setting up or removing
    // domains or their certificates.
    const hasBusyDomains = domains.some(
        (domain) => busyStatuses.includes(domain.status) || isSslBusy(domain),
    );
    const { start, stop } = usePoll(
        3000,
        { only: ['domains'] },
        { autoStart: false },
    );

    useEffect(() => {
        if (hasBusyDomains) {
            start();
        } else {
            stop();
        }
    }, [hasBusyDomains, start, stop]);

    const openDialog = (type: DomainAction['type'], domain: DomainRow) => {
        setAction({ type, domain });
        setDialogOpen(true);
    };

    const toggleForceHttps = (domain: DomainRow) => {
        router.put(
            updateForceHttps.url(domain.id),
            { force_https: !domain.force_https },
            { preserveScroll: true, onError: toastFirstError },
        );
    };

    const cancelSsl = (domain: DomainRow) => {
        router.delete(destroySsl.url(domain.id), {
            preserveScroll: true,
            onError: toastFirstError,
        });
    };

    return (
        <>
            <Head title="Domains" />

            <PageHeader
                title="Domains"
                description="Website di akun kamu. Setiap domain bisa dibuka dengan dan tanpa www."
            />

            <Panel
                title="Tambah domain"
                description={
                    <>
                        File website diletakkan di{' '}
                        <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-xs">
                            domains/&lt;domain&gt;/public_html
                        </code>{' '}
                        di folder home kamu.
                    </>
                }
            >
                <Form
                    {...store.form()}
                    resetOnSuccess
                    options={{ preserveScroll: true }}
                    className="grid max-w-2xl gap-2"
                >
                    {({ processing, errors }) => (
                        <>
                            <label htmlFor="domain" className="sr-only">
                                Nama domain
                            </label>
                            <div className="flex flex-col gap-2 sm:flex-row">
                                <div className="relative flex-1">
                                    <Globe className="pointer-events-none absolute top-1/2 left-3.5 size-[18px] -translate-y-1/2 text-muted-foreground" />
                                    <Input
                                        id="domain"
                                        name="domain"
                                        required
                                        autoComplete="off"
                                        autoCapitalize="none"
                                        spellCheck={false}
                                        maxLength={253}
                                        placeholder="contoh.com"
                                        className="pl-10"
                                        aria-invalid={
                                            errors.domain ? true : undefined
                                        }
                                    />
                                </div>
                                <Button
                                    type="submit"
                                    size="lg"
                                    disabled={processing}
                                >
                                    {processing ? <Spinner /> : <Plus />}
                                    Tambah domain
                                </Button>
                            </div>
                            <InputError message={errors.domain} />
                        </>
                    )}
                </Form>
            </Panel>

            <Panel
                title="Daftar domain"
                description={`${domains.length} domain`}
                flush
            >
                {domains.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 px-6 pt-4 pb-10 text-center">
                        <span className="flex size-14 items-center justify-center rounded-full bg-accent text-accent-foreground">
                            <Globe className="size-6" />
                        </span>
                        <p className="max-w-sm text-sm text-muted-foreground">
                            Belum ada domain. Tambahkan domain pertama di atas
                            untuk mulai membuat website.
                        </p>
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className={tableStyles.table}>
                            <thead className={tableStyles.head}>
                                <tr>
                                    <th className={tableStyles.th}>Domain</th>
                                    <th className={tableStyles.th}>Status</th>
                                    <th className={tableStyles.th}>SSL</th>
                                    <th className={tableStyles.th}>PHP</th>
                                    <th className={tableStyles.th}>
                                        Ditambahkan
                                    </th>
                                    <th className={tableStyles.th}>
                                        <span className="sr-only">Aksi</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {domains.map((domain) => (
                                    <tr
                                        key={domain.id}
                                        className={tableStyles.row}
                                    >
                                        <td className={tableStyles.td}>
                                            <DomainName domain={domain} />
                                        </td>
                                        <td className={tableStyles.td}>
                                            <StatusBadge
                                                status={domain.status}
                                            />
                                        </td>
                                        <td className={tableStyles.td}>
                                            <SslCell
                                                domain={domain}
                                                onRequest={() =>
                                                    openDialog(
                                                        'request-ssl',
                                                        domain,
                                                    )
                                                }
                                            />
                                        </td>
                                        <td className={tableStyles.td}>
                                            <span className="font-medium">
                                                {domain.php_version}
                                            </span>
                                        </td>
                                        <td
                                            className={`${tableStyles.td} whitespace-nowrap text-muted-foreground`}
                                        >
                                            {domain.created_at
                                                ? formatDateTime(
                                                      domain.created_at,
                                                  )
                                                : '—'}
                                        </td>
                                        <td className={tableStyles.td}>
                                            <RowActions
                                                domain={domain}
                                                onAction={openDialog}
                                                onToggleForceHttps={
                                                    toggleForceHttps
                                                }
                                                onCancelSsl={cancelSsl}
                                            />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Panel>

            <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                <DialogContent className="rounded-2xl">
                    {action?.type === 'request-ssl' && (
                        <RequestSslForm
                            key={`request-ssl-${action.domain.id}`}
                            domain={action.domain}
                            publicIp={public_ip}
                            onSuccess={() => setDialogOpen(false)}
                        />
                    )}
                    {action?.type === 'remove-ssl' && (
                        <RemoveSslForm
                            key={`remove-ssl-${action.domain.id}`}
                            domain={action.domain}
                            onSuccess={() => setDialogOpen(false)}
                        />
                    )}
                    {action?.type === 'delete' && (
                        <DeleteDomainForm
                            key={`delete-${action.domain.id}`}
                            domain={action.domain}
                            onSuccess={() => setDialogOpen(false)}
                        />
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}

function DomainName({ domain }: { domain: DomainRow }) {
    const scheme = domain.ssl_status === 'active' ? 'https' : 'http';

    return (
        <div className="flex items-center gap-3">
            <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-accent text-accent-foreground">
                <Globe className="size-4" />
            </span>
            <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                    {domain.status === 'active' ? (
                        <a
                            href={`${scheme}://${domain.name}`}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex items-center gap-1 font-semibold underline-offset-4 hover:text-primary hover:underline"
                        >
                            {domain.display_name}
                            <ExternalLink className="size-3.5 text-muted-foreground" />
                        </a>
                    ) : (
                        <span className="font-semibold">
                            {domain.display_name}
                        </span>
                    )}
                    {domain.is_primary && (
                        <span className="rounded-full bg-secondary px-2 py-0.5 text-[11px] font-semibold text-secondary-foreground">
                            Utama
                        </span>
                    )}
                </div>
                {domain.display_name !== domain.name && (
                    <code className="mt-0.5 block font-mono text-xs text-muted-foreground">
                        {domain.name}
                    </code>
                )}
            </div>
        </div>
    );
}

/** The certificate of one domain, with a shortcut to (re)request it. */
function SslCell({
    domain,
    onRequest,
}: {
    domain: DomainRow;
    onRequest: () => void;
}) {
    const canRequest = domain.status === 'active';

    switch (domain.ssl_status) {
        case 'active':
            return (
                <div className="flex flex-col items-start gap-1">
                    <StatusBadge status="active" icon={Lock} />
                    {domain.ssl_expires_at && (
                        <span className="text-xs whitespace-nowrap text-muted-foreground">
                            s/d {formatDate(domain.ssl_expires_at)}
                        </span>
                    )}
                </div>
            );
        case 'pending':
            return <StatusBadge status="pending" label="Diproses" />;
        case 'removing':
            return <StatusBadge status="removing" />;
        case 'failed':
            return (
                <div className="flex max-w-xs min-w-48 flex-col items-start gap-1.5">
                    <StatusBadge status="failed" />
                    {domain.ssl_error && (
                        <p className="text-xs break-words whitespace-pre-line text-muted-foreground">
                            {domain.ssl_error}
                        </p>
                    )}
                    {canRequest && (
                        <Button variant="outline" size="sm" onClick={onRequest}>
                            <RotateCw /> Coba lagi
                        </Button>
                    )}
                </div>
            );
        default:
            return (
                <div className="flex flex-col items-start gap-1.5">
                    <span className="text-sm text-muted-foreground">
                        Belum ada
                    </span>
                    {canRequest && (
                        <Button variant="outline" size="sm" onClick={onRequest}>
                            <Lock /> Pasang SSL
                        </Button>
                    )}
                </div>
            );
    }
}

/** The "⋯" menu of one domain row, with the actions its state allows. */
function RowActions({
    domain,
    onAction,
    onToggleForceHttps,
    onCancelSsl,
}: {
    domain: DomainRow;
    onAction: (type: DomainAction['type'], domain: DomainRow) => void;
    onToggleForceHttps: (domain: DomainRow) => void;
    onCancelSsl: (domain: DomainRow) => void;
}) {
    const isActive = domain.status === 'active';
    const canRequestSsl = isActive && domain.ssl_status === null;
    const canRetrySsl = isActive && domain.ssl_status === 'failed';
    const canCancelSsl = domain.ssl_status === 'failed';
    const hasActiveSsl = isActive && domain.ssl_status === 'active';
    const hasSslActions =
        canRequestSsl || canRetrySsl || canCancelSsl || hasActiveSsl;
    const canDelete =
        deletableStatuses.includes(domain.status) && !isSslBusy(domain);

    if (!hasSslActions && !canDelete) {
        return null;
    }

    return (
        <div className="flex justify-end">
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label={`Aksi untuk ${domain.display_name}`}
                    >
                        <MoreHorizontal className="size-5" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    align="end"
                    className="min-w-48 rounded-xl"
                >
                    {hasSslActions && (
                        <DropdownMenuLabel className="text-xs font-semibold text-muted-foreground">
                            SSL
                        </DropdownMenuLabel>
                    )}
                    {canRequestSsl && (
                        <DropdownMenuItem
                            onSelect={() => onAction('request-ssl', domain)}
                        >
                            <Lock /> Pasang SSL
                        </DropdownMenuItem>
                    )}
                    {canRetrySsl && (
                        <DropdownMenuItem
                            onSelect={() => onAction('request-ssl', domain)}
                        >
                            <RotateCw /> Coba lagi
                        </DropdownMenuItem>
                    )}
                    {hasActiveSsl && (
                        <DropdownMenuCheckboxItem
                            checked={domain.force_https}
                            onCheckedChange={() => onToggleForceHttps(domain)}
                        >
                            Paksa HTTPS
                        </DropdownMenuCheckboxItem>
                    )}
                    {hasActiveSsl && (
                        <DropdownMenuItem
                            onSelect={() => onAction('remove-ssl', domain)}
                        >
                            <LockOpen /> Lepas SSL
                        </DropdownMenuItem>
                    )}
                    {canCancelSsl && (
                        <DropdownMenuItem onSelect={() => onCancelSsl(domain)}>
                            <X /> Batalkan
                        </DropdownMenuItem>
                    )}
                    {hasSslActions && canDelete && <DropdownMenuSeparator />}
                    {canDelete && (
                        <DropdownMenuItem
                            variant="destructive"
                            onSelect={() => onAction('delete', domain)}
                        >
                            <Trash2 /> Hapus domain
                        </DropdownMenuItem>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    );
}

function Step({ number, children }: { number: number; children: ReactNode }) {
    return (
        <li className="flex gap-3">
            <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary">
                {number}
            </span>
            <span className="text-muted-foreground">{children}</span>
        </li>
    );
}

function Code({ children }: { children: ReactNode }) {
    return (
        <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-xs text-foreground select-all">
            {children}
        </code>
    );
}

function RequestSslForm({
    domain,
    publicIp,
    onSuccess,
}: {
    domain: DomainRow;
    publicIp: string | null;
    onSuccess: () => void;
}) {
    return (
        <Form
            {...storeSsl.form(domain.id)}
            options={{ preserveScroll: true }}
            onSuccess={onSuccess}
            className="grid gap-4"
        >
            {({ processing, errors }) => (
                <>
                    <DialogHeader>
                        <DialogTitle>
                            Pasang SSL untuk {domain.display_name}?
                        </DialogTitle>
                        <DialogDescription>
                            Sertifikat gratis dari Let&apos;s Encrypt, dipasang
                            untuk domain ini dan www-nya, lalu diperpanjang
                            otomatis.
                        </DialogDescription>
                    </DialogHeader>

                    <ol className="grid gap-3 text-sm">
                        <Step number={1}>
                            Domain harus sudah mengarah ke server ini: A record{' '}
                            <Code>{domain.display_name}</Code> dan{' '}
                            <Code>www.{domain.display_name}</Code> ke{' '}
                            {publicIp ? (
                                <Code>{publicIp}</Code>
                            ) : (
                                'IP server ini'
                            )}
                            . Boleh lewat proxy Cloudflare (awan oranye).
                        </Step>
                        <Step number={2}>
                            Kalau pakai Cloudflare dan{' '}
                            <strong className="text-foreground">
                                Always Use HTTPS
                            </strong>{' '}
                            aktif, matikan dulu sampai sertifikat terpasang.
                        </Step>
                        <Step number={3}>
                            Setelah terpasang, ubah mode SSL/TLS di Cloudflare
                            ke{' '}
                            <strong className="text-foreground">
                                Full (strict)
                            </strong>
                            .
                        </Step>
                    </ol>

                    <InputError message={errors.domain} />

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Batal
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={processing}>
                            {processing ? <Spinner /> : <Lock />}
                            Pasang SSL
                        </Button>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}

function RemoveSslForm({
    domain,
    onSuccess,
}: {
    domain: DomainRow;
    onSuccess: () => void;
}) {
    return (
        <Form
            {...destroySsl.form(domain.id)}
            options={{ preserveScroll: true }}
            onSuccess={onSuccess}
            className="grid gap-4"
        >
            {({ processing, errors }) => (
                <>
                    <DialogHeader>
                        <DialogTitle>
                            Lepas SSL dari {domain.display_name}?
                        </DialogTitle>
                        <DialogDescription>
                            Website kembali dibuka lewat HTTP saja dan
                            sertifikatnya dihapus dari server. Kalau domain ini
                            lewat proxy Cloudflare, ubah dulu mode SSL/TLS-nya
                            ke Flexible supaya website tetap bisa dibuka.
                        </DialogDescription>
                    </DialogHeader>

                    <InputError message={errors.domain} />

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
                            Lepas SSL
                        </Button>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}

function DeleteDomainForm({
    domain,
    onSuccess,
}: {
    domain: DomainRow;
    onSuccess: () => void;
}) {
    return (
        <Form
            {...destroy.form(domain.id)}
            options={{ preserveScroll: true }}
            onSuccess={onSuccess}
            className="grid gap-4"
        >
            {({ processing, errors }) => (
                <>
                    <DialogHeader>
                        <DialogTitle>Hapus {domain.display_name}?</DialogTitle>
                        <DialogDescription>
                            Website ini tidak akan dilayani lagi oleh server.
                            File di{' '}
                            <code className="font-mono text-xs">
                                domains/{domain.name}/public_html
                            </code>{' '}
                            tetap tersimpan di folder home kamu.
                            {domain.ssl_status === 'active' &&
                                ' Sertifikat SSL-nya ikut dihapus.'}
                        </DialogDescription>
                    </DialogHeader>

                    <InputError message={errors.domain} />

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
                            Hapus domain
                        </Button>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}
