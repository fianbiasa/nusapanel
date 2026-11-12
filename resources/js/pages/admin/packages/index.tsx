import { Form, Head, Link } from '@inertiajs/react';
import { MoreHorizontal, Package, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
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
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import { formatMegabytes } from '@/lib/format';
import { packageRoutes } from '@/lib/scoped-routes';
import type { AccountScope } from '@/lib/scoped-routes';
import type { HostingPackage } from '@/types';

/** "3 domain · 1 GB disk · 50.000 inode · SSL"; the inode limit only when set. */
function keyLimits(pkg: HostingPackage): string {
    const { domains, disk_mb, inodes } = pkg.limits;

    return [
        domains === null ? 'Domain tanpa batas' : `${domains} domain`,
        disk_mb === null
            ? 'Disk tanpa batas'
            : `${formatMegabytes(disk_mb)} disk`,
        inodes !== null && `${inodes.toLocaleString('id-ID')} inode`,
        pkg.ssl ? 'SSL' : 'Tanpa SSL',
        pkg.cgi && 'CGI',
    ]
        .filter(Boolean)
        .join(' · ');
}

/** "10 GB bandwidth · 10 email · 5 database", for the limits that are set. */
function otherLimits(pkg: HostingPackage): string {
    const { bandwidth_mb, email_accounts, databases } = pkg.limits;

    return [
        bandwidth_mb !== null && `${formatMegabytes(bandwidth_mb)} bandwidth`,
        email_accounts !== null && `${email_accounts} email`,
        databases !== null && `${databases} database`,
    ]
        .filter(Boolean)
        .join(' · ');
}

export default function PackagesIndex({
    scope,
    packages,
}: {
    scope: AccountScope;
    packages: HostingPackage[];
}) {
    const { create } = packageRoutes(scope);
    const [deleting, setDeleting] = useState<HostingPackage | null>(null);
    const [dialogOpen, setDialogOpen] = useState(false);

    const confirmDelete = (pkg: HostingPackage) => {
        setDeleting(pkg);
        setDialogOpen(true);
    };

    return (
        <>
            <Head title="Packages" />

            <PageHeader
                title="Packages"
                description="Batas akun hosting: kuota, jumlah domain, dan fitur yang boleh dipakai."
                actions={
                    <Button asChild size="lg">
                        <Link href={create()}>
                            <Plus />
                            Buat paket
                        </Link>
                    </Button>
                }
            />

            <Panel
                flush
                title="Daftar paket"
                description={`${packages.length} paket`}
            >
                {packages.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 px-6 pt-4 pb-10 text-center">
                        <span className="flex size-14 items-center justify-center rounded-full bg-accent text-accent-foreground">
                            <Package className="size-6" />
                        </span>
                        <p className="max-w-sm text-sm text-muted-foreground">
                            Belum ada paket. Tanpa paket, akun hosting tidak
                            punya batas. Buat paket pertama untuk mulai
                            membatasi akun.
                        </p>
                        <Button asChild variant="outline">
                            <Link href={create()}>
                                <Plus /> Buat paket
                            </Link>
                        </Button>
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className={tableStyles.table}>
                            <thead className={tableStyles.head}>
                                <tr>
                                    <th className={tableStyles.th}>Paket</th>
                                    <th className={tableStyles.th}>Batas</th>
                                    <th className={tableStyles.th}>Akun</th>
                                    <th className={tableStyles.th}>
                                        <span className="sr-only">Aksi</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {packages.map((pkg) => (
                                    <tr
                                        key={pkg.id}
                                        className={tableStyles.row}
                                    >
                                        <td className={tableStyles.td}>
                                            <div className="flex items-center gap-3">
                                                <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-accent text-accent-foreground">
                                                    <Package className="size-4" />
                                                </span>
                                                <div className="min-w-0">
                                                    <p className="truncate font-semibold">
                                                        {pkg.name}
                                                    </p>
                                                    {pkg.description && (
                                                        <p className="max-w-xs truncate text-xs text-muted-foreground">
                                                            {pkg.description}
                                                        </p>
                                                    )}
                                                </div>
                                            </div>
                                        </td>
                                        <td className={tableStyles.td}>
                                            <p className="font-medium whitespace-nowrap">
                                                {keyLimits(pkg)}
                                            </p>
                                            {otherLimits(pkg) && (
                                                <p className="text-xs whitespace-nowrap text-muted-foreground">
                                                    {otherLimits(pkg)}
                                                </p>
                                            )}
                                        </td>
                                        <td
                                            className={`${tableStyles.td} whitespace-nowrap text-muted-foreground`}
                                        >
                                            {pkg.accounts_count} akun
                                        </td>
                                        <td className={tableStyles.td}>
                                            <RowActions
                                                scope={scope}
                                                pkg={pkg}
                                                onDelete={confirmDelete}
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
                    {deleting && (
                        <DeletePackageForm
                            key={deleting.id}
                            scope={scope}
                            pkg={deleting}
                            onSuccess={() => setDialogOpen(false)}
                        />
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}

/** The "⋯" menu of one package row. */
function RowActions({
    scope,
    pkg,
    onDelete,
}: {
    scope: AccountScope;
    pkg: HostingPackage;
    onDelete: (pkg: HostingPackage) => void;
}) {
    const { edit } = packageRoutes(scope);
    const inUse = pkg.accounts_count > 0;

    return (
        <div className="flex justify-end">
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label={`Aksi untuk paket ${pkg.name}`}
                    >
                        <MoreHorizontal className="size-5" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    align="end"
                    className="max-w-60 min-w-44 rounded-xl"
                >
                    <DropdownMenuItem asChild>
                        <Link href={edit(pkg.id)}>
                            <Pencil /> Ubah
                        </Link>
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        variant="destructive"
                        disabled={inUse}
                        onSelect={() => onDelete(pkg)}
                    >
                        <Trash2 /> Hapus
                    </DropdownMenuItem>
                    {inUse && (
                        <p className="px-2 pb-1.5 text-xs text-muted-foreground">
                            Masih dipakai {pkg.accounts_count} akun. Pindahkan
                            akun-akun itu ke paket lain sebelum menghapus.
                        </p>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    );
}

function DeletePackageForm({
    scope,
    pkg,
    onSuccess,
}: {
    scope: AccountScope;
    pkg: HostingPackage;
    onSuccess: () => void;
}) {
    const { destroy } = packageRoutes(scope);

    return (
        <Form
            {...destroy.form(pkg.id)}
            options={{ preserveScroll: true }}
            onSuccess={onSuccess}
            className="grid gap-4"
        >
            {({ processing, errors }) => (
                <>
                    <DialogHeader>
                        <DialogTitle>Hapus paket {pkg.name}?</DialogTitle>
                        <DialogDescription>
                            Paket ini tidak bisa dipilih lagi untuk akun baru.
                            Tidak ada akun yang memakainya, jadi tidak ada akun
                            yang berubah.
                        </DialogDescription>
                    </DialogHeader>

                    <InputError message={errors.package} />

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
                            Hapus paket
                        </Button>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}
