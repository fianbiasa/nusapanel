import { Form, Head, usePoll } from '@inertiajs/react';
import { ExternalLink, Globe, Plus, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
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
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime } from '@/lib/format';
import { destroy, store } from '@/routes/domains';

type DomainStatus = 'pending' | 'active' | 'failed' | 'deleting';

type DomainRow = {
    id: number;
    name: string;
    display_name: string;
    php_version: string;
    is_primary: boolean;
    status: DomainStatus;
    created_at: string | null;
};

const busyStatuses: DomainStatus[] = ['pending', 'deleting'];

const deletableStatuses: DomainStatus[] = ['active', 'failed'];

export default function DomainsIndex({ domains }: { domains: DomainRow[] }) {
    const [domainToDelete, setDomainToDelete] = useState<DomainRow | null>(
        null,
    );
    const [dialogOpen, setDialogOpen] = useState(false);

    // Refresh the list while the agent is still setting up or removing domains.
    const hasBusyDomains = domains.some((domain) =>
        busyStatuses.includes(domain.status),
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

    const confirmDelete = (domain: DomainRow) => {
        setDomainToDelete(domain);
        setDialogOpen(true);
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
                                            <div className="flex items-center gap-3">
                                                <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-accent text-accent-foreground">
                                                    <Globe className="size-4" />
                                                </span>
                                                <div className="min-w-0">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        {domain.status ===
                                                        'active' ? (
                                                            <a
                                                                href={`http://${domain.name}`}
                                                                target="_blank"
                                                                rel="noreferrer"
                                                                className="inline-flex items-center gap-1 font-semibold underline-offset-4 hover:text-primary hover:underline"
                                                            >
                                                                {
                                                                    domain.display_name
                                                                }
                                                                <ExternalLink className="size-3.5 text-muted-foreground" />
                                                            </a>
                                                        ) : (
                                                            <span className="font-semibold">
                                                                {
                                                                    domain.display_name
                                                                }
                                                            </span>
                                                        )}
                                                        {domain.is_primary && (
                                                            <span className="rounded-full bg-secondary px-2 py-0.5 text-[11px] font-semibold text-secondary-foreground">
                                                                Utama
                                                            </span>
                                                        )}
                                                    </div>
                                                    {domain.display_name !==
                                                        domain.name && (
                                                        <code className="mt-0.5 block font-mono text-xs text-muted-foreground">
                                                            {domain.name}
                                                        </code>
                                                    )}
                                                </div>
                                            </div>
                                        </td>
                                        <td className={tableStyles.td}>
                                            <StatusBadge
                                                status={domain.status}
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
                                            <div className="flex justify-end">
                                                {deletableStatuses.includes(
                                                    domain.status,
                                                ) && (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        className="text-destructive-foreground hover:bg-destructive/10 hover:text-destructive-foreground"
                                                        onClick={() =>
                                                            confirmDelete(
                                                                domain,
                                                            )
                                                        }
                                                    >
                                                        <Trash2 /> Hapus
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
            </Panel>

            <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                <DialogContent className="rounded-2xl">
                    {domainToDelete && (
                        <DeleteDomainForm
                            key={domainToDelete.id}
                            domain={domainToDelete}
                            onSuccess={() => setDialogOpen(false)}
                        />
                    )}
                </DialogContent>
            </Dialog>
        </>
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
