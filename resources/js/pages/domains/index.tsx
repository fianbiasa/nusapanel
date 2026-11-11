import { Form, Head, router, usePoll } from '@inertiajs/react';
import {
    ChevronDown,
    CornerDownRight,
    ExternalLink,
    FileKey,
    FolderOpen,
    Globe,
    Info,
    Lock,
    LockOpen,
    MoreHorizontal,
    RotateCw,
    SlidersHorizontal,
    Trash2,
    X,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { AddWebsitePanel } from '@/components/add-website-panel';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/page-header';
import type { PhpSettingOptions } from '@/components/php-settings-form';
import { PhpSettingsForm } from '@/components/php-settings-form';
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
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { formatDate, formatDateTime } from '@/lib/format';
import { destroy } from '@/routes/domains';
import { store as storeCertificate } from '@/routes/domains/certificate';
import { update as updateForceHttps } from '@/routes/domains/force-https';
import { update as updateDocumentRoot } from '@/routes/domains/document-root';
import { update as updatePhpEnabled } from '@/routes/domains/php-enabled';
import { update as updateWww } from '@/routes/domains/www';
import { update as updatePhpVersion } from '@/routes/domains/php';
import { destroy as destroySsl, store as storeSsl } from '@/routes/domains/ssl';
import type { AccountLimits } from '@/types';

type DomainStatus = 'pending' | 'active' | 'failed' | 'deleting';

type SslStatus = 'pending' | 'active' | 'failed' | 'removing' | 'replacing';

type WebsiteType = 'domain' | 'subdomain' | 'pointer';

type DomainRow = {
    id: number;
    name: string;
    display_name: string;
    type: WebsiteType;
    pointer_mode: 'alias' | 'redirect' | null;
    parent_id: number | null;
    parent_display_name: string | null;
    children_count: number;
    php_version: string;
    php_settings: Record<string, string>;
    php_settings_changed: boolean;
    is_primary: boolean;
    status: DomainStatus;
    ssl_status: SslStatus | null;
    ssl_source: 'letsencrypt' | 'custom' | null;
    ssl_issuer: string | null;
    ssl_trusted: boolean | null;
    ssl_expires_at: string | null;
    ssl_error: string | null;
    force_https: boolean;
    www_mode: WwwMode;
    /** Whether the website runs PHP; a pointer shows its domain's. */
    php_enabled: boolean;
    /** The folder it is served from, relative to the home; null for a pointer. */
    document_root: string | null;
    document_root_custom: boolean;
    created_at: string | null;
};

/** Which of a website's two names its visitors end up on. */
type WwwMode = 'both' | 'www' | 'bare';

type DomainAction = {
    type:
        | 'request-ssl'
        | 'custom-certificate'
        | 'php-settings'
        | 'document-root'
        | 'remove-ssl'
        | 'delete';
    domain: DomainRow;
};

type Props = {
    domains: DomainRow[];
    php_versions: string[];
    default_php_version: string;
    php_setting_options: PhpSettingOptions;
    limits: AccountLimits;
    public_ip: string | null;
};

const typeLabels: Record<WebsiteType, string> = {
    domain: 'domain',
    subdomain: 'subdomain',
    pointer: 'pointer',
};

const busyStatuses: DomainStatus[] = ['pending', 'deleting'];

const busySslStatuses: SslStatus[] = ['pending', 'removing', 'replacing'];

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

export default function DomainsIndex({
    domains,
    php_versions,
    default_php_version,
    php_setting_options,
    limits,
    public_ip,
}: Props) {
    const [action, setAction] = useState<DomainAction | null>(null);
    const [dialogOpen, setDialogOpen] = useState(false);

    const parents = domains.filter(
        (domain) => domain.type === 'domain' && domain.status !== 'deleting',
    );

    // Refresh the list while the agent is still setting up or removing
    // domains or their certificates.
    const hasBusyDomains = domains.some(
        (domain) => busyStatuses.includes(domain.status) || isSslBusy(domain),
    );
    const { start, stop } = usePoll(
        3000,
        { only: ['domains', 'limits'] },
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

    const togglePhp = (domain: DomainRow) => {
        router.put(
            updatePhpEnabled.url(domain.id),
            { php_enabled: !domain.php_enabled },
            { preserveScroll: true, onError: toastFirstError },
        );
    };

    const changeWww = (domain: DomainRow, mode: WwwMode) => {
        if (mode === domain.www_mode) {
            return;
        }

        router.put(
            updateWww.url(domain.id),
            { www_mode: mode },
            { preserveScroll: true, onError: toastFirstError },
        );
    };

    const toggleForceHttps = (domain: DomainRow) => {
        router.put(
            updateForceHttps.url(domain.id),
            { force_https: !domain.force_https },
            { preserveScroll: true, onError: toastFirstError },
        );
    };

    const changePhpVersion = (domain: DomainRow, version: string) => {
        if (version === domain.php_version) {
            return;
        }

        router.put(
            updatePhpVersion.url(domain.id),
            { php_version: version },
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
                description="Website di akun kamu: domain, subdomain, dan pointer. Semuanya bisa dibuka dengan dan tanpa www."
            />

            <AddWebsitePanel parents={parents} limits={limits} />

            <Panel
                title="Daftar website"
                description={`${domains.length} website`}
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
                                                sslAllowed={limits.ssl}
                                                onRequest={() =>
                                                    openDialog(
                                                        'request-ssl',
                                                        domain,
                                                    )
                                                }
                                            />
                                        </td>
                                        <td className={tableStyles.td}>
                                            <PhpVersionCell
                                                domain={domain}
                                                versions={php_versions}
                                                defaultVersion={
                                                    default_php_version
                                                }
                                                onChange={changePhpVersion}
                                                onTogglePhp={togglePhp}
                                                onSettings={() =>
                                                    openDialog(
                                                        'php-settings',
                                                        domain,
                                                    )
                                                }
                                            />
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
                                                sslAllowed={limits.ssl}
                                                onAction={openDialog}
                                                onToggleForceHttps={
                                                    toggleForceHttps
                                                }
                                                onChangeWww={changeWww}
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
                    {action?.type === 'custom-certificate' && (
                        <CustomCertificateForm
                            key={`custom-certificate-${action.domain.id}`}
                            domain={action.domain}
                            onSuccess={() => setDialogOpen(false)}
                        />
                    )}
                    {action?.type === 'php-settings' && (
                        <PhpSettingsForm
                            key={`php-settings-${action.domain.id}`}
                            domain={action.domain}
                            settings={action.domain.php_settings}
                            options={php_setting_options}
                            onSuccess={() => setDialogOpen(false)}
                        />
                    )}
                    {action?.type === 'document-root' && (
                        <DocumentRootForm
                            key={`document-root-${action.domain.id}`}
                            domain={action.domain}
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
    const isChild = domain.parent_id !== null;

    return (
        <div className={`flex items-center gap-3 ${isChild ? 'pl-6' : ''}`}>
            {isChild ? (
                <CornerDownRight className="size-4 shrink-0 text-muted-foreground" />
            ) : (
                <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-accent text-accent-foreground">
                    <Globe className="size-4" />
                </span>
            )}
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
                    {domain.type !== 'domain' && (
                        <span className="rounded-full bg-muted px-2 py-0.5 text-[11px] font-semibold text-muted-foreground">
                            {domain.type === 'subdomain'
                                ? 'Subdomain'
                                : 'Pointer'}
                        </span>
                    )}
                </div>
                {domain.display_name !== domain.name && (
                    <code className="mt-0.5 block font-mono text-xs text-muted-foreground">
                        {domain.name}
                    </code>
                )}
                {domain.type === 'pointer' && (
                    <span className="mt-0.5 block text-xs text-muted-foreground">
                        {domain.pointer_mode === 'redirect'
                            ? `Dialihkan ke ${domain.parent_display_name}`
                            : `Menampilkan website ${domain.parent_display_name}`}
                    </span>
                )}
            </div>
        </div>
    );
}

/**
 * The PHP version of one website, which a domain or subdomain can switch to
 * any installed version. A pointer runs on its domain's version; one that
 * redirects runs no PHP at all.
 */
function PhpVersionCell({
    domain,
    versions,
    defaultVersion,
    onChange,
    onTogglePhp,
    onSettings,
}: {
    domain: DomainRow;
    versions: string[];
    defaultVersion: string;
    onChange: (domain: DomainRow, version: string) => void;
    onTogglePhp: (domain: DomainRow) => void;
    onSettings: () => void;
}) {
    if (domain.pointer_mode === 'redirect') {
        return <span className="font-medium text-muted-foreground">—</span>;
    }

    const canChange =
        domain.type !== 'pointer' &&
        (domain.status === 'active' || domain.status === 'failed') &&
        !isSslBusy(domain);

    if (!canChange) {
        return (
            <span className="font-medium">
                {domain.php_enabled ? domain.php_version : 'PHP mati'}
            </span>
        );
    }

    if (!domain.php_enabled) {
        return (
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        variant="outline"
                        size="sm"
                        className="gap-1 rounded-lg font-semibold text-muted-foreground"
                        aria-label={`PHP ${domain.display_name}: mati`}
                    >
                        PHP mati
                        <ChevronDown className="size-3.5" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    align="start"
                    className="min-w-44 rounded-xl"
                >
                    <DropdownMenuCheckboxItem
                        checked={false}
                        onCheckedChange={() => onTogglePhp(domain)}
                    >
                        PHP aktif
                    </DropdownMenuCheckboxItem>
                </DropdownMenuContent>
            </DropdownMenu>
        );
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="outline"
                    size="sm"
                    className="gap-1 rounded-lg font-semibold"
                    aria-label={`PHP ${domain.display_name}: versi ${domain.php_version}${domain.php_settings_changed ? ', pengaturan disesuaikan' : ''}`}
                >
                    {domain.php_version}
                    {domain.php_settings_changed && (
                        <SlidersHorizontal className="size-3.5 text-primary" />
                    )}
                    <ChevronDown className="size-3.5 text-muted-foreground" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="min-w-44 rounded-xl">
                <DropdownMenuLabel className="text-xs font-semibold text-muted-foreground">
                    Versi PHP
                </DropdownMenuLabel>
                <DropdownMenuRadioGroup
                    value={domain.php_version}
                    onValueChange={(version) => onChange(domain, version)}
                >
                    {versions.map((version) => (
                        <DropdownMenuRadioItem key={version} value={version}>
                            PHP {version}
                            {version === defaultVersion && (
                                <span className="ml-auto text-xs text-muted-foreground">
                                    bawaan
                                </span>
                            )}
                        </DropdownMenuRadioItem>
                    ))}
                </DropdownMenuRadioGroup>
                <DropdownMenuSeparator />
                <DropdownMenuItem onSelect={onSettings}>
                    <SlidersHorizontal /> Pengaturan PHP…
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuCheckboxItem
                    checked
                    onCheckedChange={() => onTogglePhp(domain)}
                >
                    PHP aktif
                </DropdownMenuCheckboxItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * The certificate of one domain, with a shortcut to (re)request it when
 * the account's package includes SSL.
 */
function SslCell({
    domain,
    sslAllowed,
    onRequest,
}: {
    domain: DomainRow;
    sslAllowed: boolean;
    onRequest: () => void;
}) {
    const canRequest = domain.status === 'active' && sslAllowed;

    switch (domain.ssl_status) {
        case 'active':
        case 'replacing':
            return <ActiveCertificate domain={domain} />;
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
                    {!sslAllowed && <SslNotIncluded />}
                </div>
            );
    }
}

/** Why "Pasang SSL" is missing: the package does not include SSL. */
function SslNotIncluded() {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span
                    tabIndex={0}
                    className="inline-flex cursor-help items-center gap-1 text-xs whitespace-nowrap text-muted-foreground"
                >
                    <Info className="size-3.5" />
                    Tidak termasuk paket
                </span>
            </TooltipTrigger>
            <TooltipContent>
                SSL tidak termasuk dalam paket kamu. Minta paket dengan SSL ke
                penyedia hosting kamu.
            </TooltipContent>
        </Tooltip>
    );
}

/** The "⋯" menu of one domain row, with the actions its state allows. */
function RowActions({
    domain,
    sslAllowed,
    onAction,
    onToggleForceHttps,
    onChangeWww,
    onCancelSsl,
}: {
    domain: DomainRow;
    sslAllowed: boolean;
    onAction: (type: DomainAction['type'], domain: DomainRow) => void;
    onToggleForceHttps: (domain: DomainRow) => void;
    onChangeWww: (domain: DomainRow, mode: WwwMode) => void;
    onCancelSsl: (domain: DomainRow) => void;
}) {
    const isActive = domain.status === 'active';
    // A redirecting pointer sends everyone to its domain anyway.
    const canChooseWww = isActive && domain.pointer_mode !== 'redirect';
    const canChooseFolder =
        domain.type === 'subdomain' && (isActive || domain.status === 'failed');
    const canRequestSsl = isActive && sslAllowed && domain.ssl_status === null;
    const canRetrySsl =
        isActive && sslAllowed && domain.ssl_status === 'failed';
    const canCancelSsl = domain.ssl_status === 'failed';
    const hasActiveSsl = isActive && domain.ssl_status === 'active';
    const canInstallCertificate =
        isActive &&
        sslAllowed &&
        (domain.ssl_status === null ||
            domain.ssl_status === 'failed' ||
            domain.ssl_status === 'active');
    const hasSslActions =
        canRequestSsl ||
        canRetrySsl ||
        canCancelSsl ||
        hasActiveSsl ||
        canInstallCertificate;
    const canDelete =
        deletableStatuses.includes(domain.status) && !isSslBusy(domain);

    if (!hasSslActions && !canChooseWww && !canChooseFolder && !canDelete) {
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
                    {canInstallCertificate && (
                        <DropdownMenuItem
                            onSelect={() =>
                                onAction('custom-certificate', domain)
                            }
                        >
                            <FileKey />
                            {domain.ssl_source === 'custom'
                                ? 'Ganti sertifikat'
                                : 'Pasang sertifikat sendiri'}
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
                    {canChooseFolder && (
                        <>
                            {hasSslActions && <DropdownMenuSeparator />}
                            <DropdownMenuItem
                                onSelect={() =>
                                    onAction('document-root', domain)
                                }
                            >
                                <FolderOpen /> Folder website…
                            </DropdownMenuItem>
                        </>
                    )}
                    {canChooseWww && (
                        <>
                            {(hasSslActions || canChooseFolder) && (
                                <DropdownMenuSeparator />
                            )}
                            <DropdownMenuLabel className="text-xs font-semibold text-muted-foreground">
                                Alamat www
                            </DropdownMenuLabel>
                            <DropdownMenuRadioGroup
                                value={domain.www_mode}
                                onValueChange={(mode) =>
                                    onChangeWww(domain, mode as WwwMode)
                                }
                            >
                                <DropdownMenuRadioItem value="both">
                                    Dengan &amp; tanpa www
                                </DropdownMenuRadioItem>
                                <DropdownMenuRadioItem value="www">
                                    Selalu www.{domain.display_name}
                                </DropdownMenuRadioItem>
                                <DropdownMenuRadioItem value="bare">
                                    Selalu tanpa www
                                </DropdownMenuRadioItem>
                            </DropdownMenuRadioGroup>
                        </>
                    )}
                    {(hasSslActions || canChooseWww || canChooseFolder) &&
                        canDelete && <DropdownMenuSeparator />}
                    {canDelete && (
                        <DropdownMenuItem
                            variant="destructive"
                            onSelect={() => onAction('delete', domain)}
                        >
                            <Trash2 /> Hapus {typeLabels[domain.type]}
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

/** Days until the certificate expires; negative once it has. */
function daysUntil(date: string): number {
    return Math.floor((new Date(date).getTime() - Date.now()) / 86_400_000);
}

/**
 * An installed certificate: where it comes from, until when it is valid
 * (a custom one is never renewed, so its end is pointed out), and anything
 * the customer should know about it.
 */
function ActiveCertificate({ domain }: { domain: DomainRow }) {
    const isCustom = domain.ssl_source === 'custom';
    const days =
        domain.ssl_expires_at === null
            ? null
            : daysUntil(domain.ssl_expires_at);

    return (
        <div className="flex max-w-xs min-w-40 flex-col items-start gap-1">
            {domain.ssl_status === 'replacing' ? (
                <StatusBadge status="replacing" icon={Lock} />
            ) : (
                <StatusBadge status="active" icon={Lock} />
            )}
            <span className="text-xs text-muted-foreground">
                {isCustom
                    ? `Sertifikat sendiri${domain.ssl_issuer ? ` · ${domain.ssl_issuer}` : ''}`
                    : "Let's Encrypt"}
            </span>
            {domain.ssl_expires_at && (
                <span
                    className={
                        isCustom && days !== null && days <= 14
                            ? 'text-xs font-semibold whitespace-nowrap text-destructive'
                            : 'text-xs whitespace-nowrap text-muted-foreground'
                    }
                >
                    {days !== null && days < 0
                        ? `Kedaluwarsa ${formatDate(domain.ssl_expires_at)}`
                        : `s/d ${formatDate(domain.ssl_expires_at)}`}
                </span>
            )}
            {isCustom && domain.ssl_trusted === false && (
                <Tooltip>
                    <TooltipTrigger asChild>
                        <span
                            tabIndex={0}
                            className="inline-flex cursor-help items-center gap-1 text-xs text-muted-foreground"
                        >
                            <Info className="size-3.5" />
                            Tidak dipercaya browser
                        </span>
                    </TooltipTrigger>
                    <TooltipContent className="max-w-64">
                        Browser tidak mengenal penerbitnya. Cocok untuk
                        Cloudflare Origin Certificate dengan proxy Cloudflare
                        (mode SSL/TLS Full strict), tapi tidak untuk pengunjung
                        langsung.
                    </TooltipContent>
                </Tooltip>
            )}
            {domain.ssl_error && (
                <p className="text-xs break-words whitespace-pre-line text-destructive">
                    Sertifikat baru gagal dipasang: {domain.ssl_error}
                </p>
            )}
        </div>
    );
}

const textareaClass =
    'min-h-32 w-full rounded-xl border border-input bg-card px-3.5 py-2.5 font-mono text-xs shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive aria-invalid:ring-destructive/20';

function CustomCertificateForm({
    domain,
    onSuccess,
}: {
    domain: DomainRow;
    onSuccess: () => void;
}) {
    const replaces = domain.ssl_status === 'active';

    return (
        <Form
            {...storeCertificate.form(domain.id)}
            options={{ preserveScroll: true }}
            onSuccess={onSuccess}
            className="grid gap-4"
        >
            {({ processing, errors }) => (
                <>
                    <DialogHeader>
                        <DialogTitle>
                            {replaces
                                ? `Ganti sertifikat ${domain.display_name}`
                                : `Pasang sertifikat sendiri untuk ${domain.display_name}`}
                        </DialogTitle>
                        <DialogDescription>
                            Sertifikat yang kamu beli, atau Cloudflare Origin
                            Certificate. Tidak diperpanjang otomatis: ganti
                            sebelum masa berlakunya habis.
                            {replaces &&
                                ' Website tetap memakai sertifikat yang sekarang sampai yang baru terpasang.'}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <label
                            htmlFor="certificate"
                            className="text-sm font-semibold"
                        >
                            Sertifikat (PEM)
                        </label>
                        <textarea
                            id="certificate"
                            name="certificate"
                            required
                            spellCheck={false}
                            autoComplete="off"
                            placeholder={
                                '-----BEGIN CERTIFICATE-----\n...\n-----END CERTIFICATE-----'
                            }
                            className={textareaClass}
                            aria-invalid={errors.certificate ? true : undefined}
                        />
                        <p className="text-xs text-muted-foreground">
                            Sertifikat domain dulu, lalu sertifikat intermediate
                            / CA bundle dari penerbitnya.
                        </p>
                        <InputError message={errors.certificate} />
                    </div>

                    <div className="grid gap-2">
                        <label
                            htmlFor="private_key"
                            className="text-sm font-semibold"
                        >
                            Private key (PEM)
                        </label>
                        <textarea
                            id="private_key"
                            name="private_key"
                            required
                            spellCheck={false}
                            autoComplete="off"
                            placeholder={
                                '-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----'
                            }
                            className={textareaClass}
                            aria-invalid={errors.private_key ? true : undefined}
                        />
                        <p className="text-xs text-muted-foreground">
                            Tanpa password. Disimpan hanya di server, tidak bisa
                            dilihat lagi dari panel.
                        </p>
                        <InputError message={errors.private_key} />
                    </div>

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Batal
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={processing}>
                            {processing ? <Spinner /> : <FileKey />}
                            {replaces
                                ? 'Ganti sertifikat'
                                : 'Pasang sertifikat'}
                        </Button>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}

/** The folder a subdomain is served from, inside domains/ of the home. */
function DocumentRootForm({
    domain,
    onSuccess,
}: {
    domain: DomainRow;
    onSuccess: () => void;
}) {
    const ownFolder = `domains/${domain.name}/public_html`;
    const [value, setValue] = useState(domain.document_root ?? ownFolder);

    return (
        <Form
            {...updateDocumentRoot.form(domain.id)}
            options={{ preserveScroll: true }}
            onSuccess={onSuccess}
            className="grid gap-4"
        >
            {({ processing, errors }) => (
                <>
                    <DialogHeader>
                        <DialogTitle>
                            Folder website {domain.display_name}
                        </DialogTitle>
                        <DialogDescription>
                            Folder di dalam <code>domains/</code> di home kamu,
                            misalnya{' '}
                            <code>domains/example.com/public_html/blog</code>.
                            Folder dibuat kalau belum ada; file di folder lama
                            tetap tersimpan.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Input
                            name="document_root"
                            value={value}
                            onChange={(event) => setValue(event.target.value)}
                            className="rounded-xl font-mono"
                            autoComplete="off"
                            aria-invalid={
                                errors.document_root ? true : undefined
                            }
                        />
                        <InputError message={errors.document_root} />
                        {value !== ownFolder && (
                            <button
                                type="button"
                                className="justify-self-start text-sm text-primary underline-offset-4 hover:underline"
                                onClick={() => setValue(ownFolder)}
                            >
                                Pakai folder bawaan ({ownFolder})
                            </button>
                        )}
                    </div>

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Batal
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={processing}>
                            {processing && <Spinner />}
                            Simpan
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
                            {domain.type === 'pointer' ? (
                                'Pointer ini tidak akan dilayani lagi oleh server. Website domain induknya tidak berubah.'
                            ) : (
                                <>
                                    Website ini tidak akan dilayani lagi oleh
                                    server. File di{' '}
                                    <code className="font-mono text-xs">
                                        {domain.document_root ??
                                            `domains/${domain.name}/public_html`}
                                    </code>{' '}
                                    tetap tersimpan di folder home kamu.
                                </>
                            )}
                            {domain.ssl_status === 'active' &&
                                ' Sertifikat SSL-nya ikut dihapus.'}
                            {domain.children_count > 0 &&
                                ` ${domain.children_count} subdomain dan pointer milik domain ini ikut dihapus.`}
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
                            Hapus {typeLabels[domain.type]}
                        </Button>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}
