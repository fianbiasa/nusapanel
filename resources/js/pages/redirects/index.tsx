import { Form, Head } from '@inertiajs/react';
import {
    ArrowRight,
    ExternalLink,
    Milestone,
    Plus,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
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
import { formatDateTime } from '@/lib/format';
import { destroy, store } from '@/routes/redirects';

type RedirectRow = {
    id: number;
    domain: string;
    domain_display_name: string;
    path: string;
    destination: string;
    status_code: 301 | 302;
    wildcard: boolean;
    created_at: string | null;
};

type Website = {
    id: number;
    name: string;
    display_name: string;
    ssl: boolean;
};

type Props = {
    redirects: RedirectRow[];
    websites: Website[];
    max_per_website: number;
};

const statusLabels: Record<RedirectRow['status_code'], string> = {
    301: 'Permanen (301)',
    302: 'Sementara (302)',
};

export default function RedirectsIndex({
    redirects,
    websites,
    max_per_website,
}: Props) {
    const [deleting, setDeleting] = useState<RedirectRow | null>(null);
    const [dialogOpen, setDialogOpen] = useState(false);

    return (
        <>
            <Head title="Redirects" />

            <PageHeader
                title="Redirects"
                description="Alihkan alamat di website kamu ke URL lain, misalnya contoh.com/promo ke halaman toko."
            />

            <Panel
                title="Tambah pengalihan"
                description={`Paling banyak ${max_per_website} pengalihan per website. Berlaku begitu website-nya selesai diperbarui, biasanya beberapa detik.`}
            >
                {websites.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Belum ada website aktif. Tambahkan domain di halaman
                        Domains dulu.
                    </p>
                ) : (
                    <AddRedirectForm websites={websites} />
                )}
            </Panel>

            <Panel
                title="Daftar pengalihan"
                description={`${redirects.length} pengalihan`}
                flush
            >
                {redirects.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 px-6 pt-4 pb-10 text-center">
                        <span className="flex size-14 items-center justify-center rounded-full bg-accent text-accent-foreground">
                            <Milestone className="size-6" />
                        </span>
                        <p className="max-w-sm text-sm text-muted-foreground">
                            Belum ada pengalihan.
                        </p>
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className={tableStyles.table}>
                            <thead className={tableStyles.head}>
                                <tr>
                                    <th className={tableStyles.th}>Dari</th>
                                    <th className={tableStyles.th}>Ke</th>
                                    <th className={tableStyles.th}>Jenis</th>
                                    <th className={tableStyles.th}>
                                        Ditambahkan
                                    </th>
                                    <th className={tableStyles.th}>
                                        <span className="sr-only">Aksi</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {redirects.map((redirect) => (
                                    <tr
                                        key={redirect.id}
                                        className={tableStyles.row}
                                    >
                                        <td className={tableStyles.td}>
                                            <div className="font-semibold break-all">
                                                {redirect.domain_display_name}
                                                <span className="text-primary">
                                                    {redirect.path}
                                                </span>
                                            </div>
                                            {redirect.wildcard && (
                                                <span className="text-xs text-muted-foreground">
                                                    termasuk semua halaman di
                                                    bawahnya
                                                </span>
                                            )}
                                        </td>
                                        <td className={tableStyles.td}>
                                            <a
                                                href={redirect.destination}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="inline-flex max-w-xs items-center gap-1 break-all underline-offset-4 hover:text-primary hover:underline"
                                            >
                                                {redirect.destination}
                                                <ExternalLink className="size-3.5 shrink-0 text-muted-foreground" />
                                            </a>
                                        </td>
                                        <td
                                            className={`${tableStyles.td} whitespace-nowrap`}
                                        >
                                            {statusLabels[redirect.status_code]}
                                        </td>
                                        <td
                                            className={`${tableStyles.td} whitespace-nowrap text-muted-foreground`}
                                        >
                                            {redirect.created_at
                                                ? formatDateTime(
                                                      redirect.created_at,
                                                  )
                                                : '—'}
                                        </td>
                                        <td className={tableStyles.td}>
                                            <div className="flex justify-end">
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={`Hapus pengalihan ${redirect.domain_display_name}${redirect.path}`}
                                                    onClick={() => {
                                                        setDeleting(redirect);
                                                        setDialogOpen(true);
                                                    }}
                                                >
                                                    <Trash2 className="size-4" />
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

            <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                <DialogContent className="rounded-2xl">
                    {deleting && (
                        <DeleteRedirectForm
                            key={deleting.id}
                            redirect={deleting}
                            onSuccess={() => setDialogOpen(false)}
                        />
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}

function AddRedirectForm({ websites }: { websites: Website[] }) {
    const [websiteId, setWebsiteId] = useState(String(websites[0].id));
    const [statusCode, setStatusCode] = useState('301');
    const [wildcard, setWildcard] = useState(false);

    const website =
        websites.find((option) => String(option.id) === websiteId) ??
        websites[0];

    return (
        <Form
            {...store.form()}
            resetOnSuccess={['path', 'destination']}
            options={{ preserveScroll: true }}
            className="grid max-w-3xl gap-4"
        >
            {({ processing, errors }) => (
                <>
                    <input type="hidden" name="domain_id" value={websiteId} />
                    <input
                        type="hidden"
                        name="status_code"
                        value={statusCode}
                    />
                    <input
                        type="hidden"
                        name="wildcard"
                        value={wildcard ? '1' : '0'}
                    />

                    <div className="grid gap-2">
                        <Label htmlFor="redirect-path">
                            Alamat yang dialihkan
                        </Label>
                        <div className="flex flex-col gap-2 sm:flex-row">
                            <Select
                                value={websiteId}
                                onValueChange={setWebsiteId}
                            >
                                <SelectTrigger
                                    aria-label="Website"
                                    aria-invalid={
                                        errors.domain_id ? true : undefined
                                    }
                                    className="w-full rounded-xl bg-card px-3.5 data-[size=default]:h-11 sm:w-auto sm:min-w-56"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent className="rounded-xl">
                                    {websites.map((option) => (
                                        <SelectItem
                                            key={option.id}
                                            value={String(option.id)}
                                        >
                                            {option.display_name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Input
                                id="redirect-path"
                                name="path"
                                required
                                autoComplete="off"
                                autoCapitalize="none"
                                spellCheck={false}
                                maxLength={255}
                                placeholder="/promo"
                                className="flex-1"
                                aria-invalid={errors.path ? true : undefined}
                            />
                        </div>
                        <p className="text-xs text-muted-foreground">
                            Contoh: <code className="font-mono">/promo</code>{' '}
                            untuk {website.ssl ? 'https' : 'http'}://
                            {website.display_name}/promo. Isi{' '}
                            <code className="font-mono">/</code> untuk seluruh
                            website.
                        </p>
                        <InputError message={errors.domain_id ?? errors.path} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="redirect-destination">
                            Dialihkan ke
                        </Label>
                        <div className="flex items-center gap-2">
                            <ArrowRight className="size-5 shrink-0 text-muted-foreground" />
                            <Input
                                id="redirect-destination"
                                name="destination"
                                required
                                autoComplete="off"
                                autoCapitalize="none"
                                spellCheck={false}
                                maxLength={2048}
                                placeholder="https://toko.contoh.com/diskon"
                                aria-invalid={
                                    errors.destination ? true : undefined
                                }
                            />
                        </div>
                        <InputError message={errors.destination} />
                    </div>

                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:gap-6">
                        <div className="grid gap-2">
                            <Label htmlFor="redirect-status">Jenis</Label>
                            <Select
                                value={statusCode}
                                onValueChange={setStatusCode}
                            >
                                <SelectTrigger
                                    id="redirect-status"
                                    className="w-full rounded-xl bg-card px-3.5 data-[size=default]:h-11 sm:w-56"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent className="rounded-xl">
                                    <SelectItem value="301">
                                        {statusLabels[301]}
                                    </SelectItem>
                                    <SelectItem value="302">
                                        {statusLabels[302]}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="flex items-start gap-3 sm:pt-6">
                            <Checkbox
                                id="redirect-wildcard"
                                checked={wildcard}
                                onCheckedChange={(checked) =>
                                    setWildcard(checked === true)
                                }
                            />
                            <div className="grid gap-1">
                                <Label htmlFor="redirect-wildcard">
                                    Termasuk semua halaman di bawahnya
                                </Label>
                                <p className="text-xs text-muted-foreground">
                                    /promo/sepatu ikut dialihkan ke URL tujuan +
                                    /sepatu.
                                </p>
                            </div>
                        </div>
                    </div>
                    <InputError
                        message={errors.status_code ?? errors.wildcard}
                    />

                    <div>
                        <Button type="submit" size="lg" disabled={processing}>
                            {processing ? <Spinner /> : <Plus />}
                            Tambah pengalihan
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function DeleteRedirectForm({
    redirect,
    onSuccess,
}: {
    redirect: RedirectRow;
    onSuccess: () => void;
}) {
    return (
        <Form
            {...destroy.form(redirect.id)}
            options={{ preserveScroll: true }}
            onSuccess={onSuccess}
            className="grid gap-4"
        >
            {({ processing }) => (
                <>
                    <DialogHeader>
                        <DialogTitle>Hapus pengalihan ini?</DialogTitle>
                        <DialogDescription>
                            {redirect.domain_display_name}
                            {redirect.path} tidak lagi dialihkan ke{' '}
                            {redirect.destination}.
                        </DialogDescription>
                    </DialogHeader>

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
                            Hapus pengalihan
                        </Button>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}
