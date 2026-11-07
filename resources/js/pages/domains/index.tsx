import { Form, Head, usePoll } from '@inertiajs/react';
import { ExternalLink, Plus } from 'lucide-react';
import { useEffect, useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
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
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime } from '@/lib/format';
import { destroy, index, store } from '@/routes/domains';

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

const statusVariants: Record<
    DomainStatus,
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    pending: 'outline',
    active: 'default',
    failed: 'destructive',
    deleting: 'outline',
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
            <div className="flex flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Domains"
                    description="Websites on your account. Each one is served from domains/<domain>/public_html in your home directory, with and without www."
                />

                <Form
                    {...store.form()}
                    resetOnSuccess
                    options={{ preserveScroll: true }}
                    className="grid w-full max-w-xl gap-2"
                >
                    {({ processing, errors }) => (
                        <>
                            <Label htmlFor="domain">Add a domain</Label>
                            <div className="flex items-center gap-2">
                                <Input
                                    id="domain"
                                    name="domain"
                                    required
                                    autoComplete="off"
                                    autoCapitalize="none"
                                    spellCheck={false}
                                    maxLength={253}
                                    placeholder="example.com"
                                    aria-invalid={
                                        errors.domain ? true : undefined
                                    }
                                />
                                <Button type="submit" disabled={processing}>
                                    {processing ? <Spinner /> : <Plus />}
                                    Add domain
                                </Button>
                            </div>
                            <InputError message={errors.domain} />
                        </>
                    )}
                </Form>

                {domains.length === 0 ? (
                    <p className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                        No domains yet. Add one to start hosting a website.
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                <tr>
                                    <th className="px-4 py-2 font-medium">
                                        Domain
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Status
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        PHP
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Added
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        <span className="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {domains.map((domain) => (
                                    <tr key={domain.id} className="align-top">
                                        <td className="px-4 py-3">
                                            <div className="flex flex-wrap items-center gap-2">
                                                {domain.status === 'active' ? (
                                                    <a
                                                        href={`http://${domain.name}`}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        className="inline-flex items-center gap-1 font-medium underline-offset-4 hover:underline"
                                                    >
                                                        {domain.display_name}
                                                        <ExternalLink className="size-3 text-muted-foreground" />
                                                    </a>
                                                ) : (
                                                    <span className="font-medium">
                                                        {domain.display_name}
                                                    </span>
                                                )}
                                                {domain.is_primary && (
                                                    <Badge variant="secondary">
                                                        Primary
                                                    </Badge>
                                                )}
                                            </div>
                                            {domain.display_name !==
                                                domain.name && (
                                                <code className="mt-1 block font-mono text-xs text-muted-foreground">
                                                    {domain.name}
                                                </code>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            <Badge
                                                variant={
                                                    statusVariants[
                                                        domain.status
                                                    ]
                                                }
                                            >
                                                {busyStatuses.includes(
                                                    domain.status,
                                                ) && <Spinner />}
                                                {domain.status}
                                            </Badge>
                                        </td>
                                        <td className="px-4 py-3">
                                            {domain.php_version}
                                        </td>
                                        <td className="px-4 py-3 whitespace-nowrap">
                                            {domain.created_at
                                                ? formatDateTime(
                                                      domain.created_at,
                                                  )
                                                : '—'}
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex justify-end">
                                                {deletableStatuses.includes(
                                                    domain.status,
                                                ) && (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                                        onClick={() =>
                                                            confirmDelete(
                                                                domain,
                                                            )
                                                        }
                                                    >
                                                        Delete
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
            </div>

            <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                <DialogContent>
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
                        <DialogTitle>Delete {domain.display_name}</DialogTitle>
                        <DialogDescription>
                            The website stops being served from this server. Its
                            files in{' '}
                            <code className="font-mono text-xs">
                                domains/{domain.name}/public_html
                            </code>{' '}
                            are kept in your home directory.
                        </DialogDescription>
                    </DialogHeader>

                    <InputError message={errors.domain} />

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Cancel
                            </Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={processing}
                        >
                            {processing && <Spinner />}
                            Delete domain
                        </Button>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}

DomainsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Domains',
            href: index(),
        },
    ],
};
