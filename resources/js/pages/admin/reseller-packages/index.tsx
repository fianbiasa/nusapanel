import { Form, Head, Link } from '@inertiajs/react';
import { Boxes, MoreHorizontal, Pencil, Plus, Trash2 } from 'lucide-react';
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
import { create, destroy, edit } from '@/routes/admin/reseller-packages';
import type { ResellerPackage } from '@/types';

/** "10 akun · 10 GB disk · 30 domain · SSL · overselling" */
function keyLimits(pkg: ResellerPackage): string {
    const { accounts, disk_mb, domains } = pkg.limits;

    return [
        accounts === null ? 'Akun tanpa batas' : `${accounts} akun`,
        disk_mb === null
            ? 'Disk tanpa batas'
            : `${formatMegabytes(disk_mb)} disk`,
        domains === null ? 'Domain tanpa batas' : `${domains} domain`,
        pkg.ssl ? 'SSL' : 'Tanpa SSL',
        pkg.oversell && 'Overselling',
    ]
        .filter(Boolean)
        .join(' · ');
}

export default function ResellerPackagesIndex({
    packages,
}: {
    packages: ResellerPackage[];
}) {
    const [deleting, setDeleting] = useState<ResellerPackage | null>(null);
    const [dialogOpen, setDialogOpen] = useState(false);

    return (
        <>
            <Head title="Paket reseller" />

            <PageHeader
                title="Paket reseller"
                description="Jatah reseller: berapa akun yang boleh dibuat dan berapa kuota yang boleh dibagikan ke semua akunnya."
                actions={
                    <Button asChild size="lg">
                        <Link href={create()}>
                            <Plus />
                            Buat paket reseller
                        </Link>
                    </Button>
                }
            />

            <Panel
                flush
                title="Daftar paket reseller"
                description={`${packages.length} paket`}
            >
                {packages.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 px-6 pt-4 pb-10 text-center">
                        <span className="flex size-14 items-center justify-center rounded-full bg-accent text-accent-foreground">
                            <Boxes className="size-6" />
                        </span>
                        <p className="max-w-sm text-sm text-muted-foreground">
                            Belum ada paket reseller. Tanpa paket, reseller
                            boleh membuat akun dan membagikan kuota tanpa batas.
                        </p>
                        <Button asChild variant="outline">
                            <Link href={create()}>
                                <Plus /> Buat paket reseller
                            </Link>
                        </Button>
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className={tableStyles.table}>
                            <thead className={tableStyles.head}>
                                <tr>
                                    <th className={tableStyles.th}>Paket</th>
                                    <th className={tableStyles.th}>Jatah</th>
                                    <th className={tableStyles.th}>Reseller</th>
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
                                            <p className="font-semibold">
                                                {pkg.name}
                                            </p>
                                            {pkg.description && (
                                                <p className="max-w-xs truncate text-xs text-muted-foreground">
                                                    {pkg.description}
                                                </p>
                                            )}
                                        </td>
                                        <td
                                            className={`${tableStyles.td} whitespace-nowrap`}
                                        >
                                            {keyLimits(pkg)}
                                        </td>
                                        <td
                                            className={`${tableStyles.td} whitespace-nowrap text-muted-foreground`}
                                        >
                                            {pkg.resellers_count} reseller
                                        </td>
                                        <td className={tableStyles.td}>
                                            <div className="flex justify-end">
                                                <DropdownMenu>
                                                    <DropdownMenuTrigger
                                                        asChild
                                                    >
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
                                                        <DropdownMenuItem
                                                            asChild
                                                        >
                                                            <Link
                                                                href={edit(
                                                                    pkg.id,
                                                                )}
                                                            >
                                                                <Pencil /> Ubah
                                                            </Link>
                                                        </DropdownMenuItem>
                                                        <DropdownMenuItem
                                                            variant="destructive"
                                                            disabled={
                                                                pkg.resellers_count >
                                                                0
                                                            }
                                                            onSelect={() => {
                                                                setDeleting(
                                                                    pkg,
                                                                );
                                                                setDialogOpen(
                                                                    true,
                                                                );
                                                            }}
                                                        >
                                                            <Trash2 /> Hapus
                                                        </DropdownMenuItem>
                                                        {pkg.resellers_count >
                                                            0 && (
                                                            <p className="px-2 pb-1.5 text-xs text-muted-foreground">
                                                                Masih dipakai{' '}
                                                                {
                                                                    pkg.resellers_count
                                                                }{' '}
                                                                reseller.
                                                            </p>
                                                        )}
                                                    </DropdownMenuContent>
                                                </DropdownMenu>
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
                    {deleting && (
                        <Form
                            key={deleting.id}
                            {...destroy.form(deleting.id)}
                            options={{ preserveScroll: true }}
                            onSuccess={() => setDialogOpen(false)}
                            className="grid gap-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <DialogHeader>
                                        <DialogTitle>
                                            Hapus paket reseller {deleting.name}
                                            ?
                                        </DialogTitle>
                                        <DialogDescription>
                                            Paket ini tidak bisa dipilih lagi
                                            untuk reseller. Tidak ada reseller
                                            yang memakainya.
                                        </DialogDescription>
                                    </DialogHeader>
                                    <InputError message={errors.package} />
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
                                            Hapus paket
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
