import { Form, Head } from '@inertiajs/react';
import { Network, Pencil, Plus, Trash2 } from 'lucide-react';
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
import type { AccountScope } from '@/lib/scoped-routes';
import * as adminIpAddresses from '@/routes/admin/ip-addresses';
import * as resellerIpAddresses from '@/routes/reseller/ip-addresses';

type IpAddressRow = {
    id: number;
    address: string;
    version: 4 | 6;
    prefix: number;
    /** The reseller it was given to; null for the administrators'. */
    owner: { id: number; username: string } | null;
    dedicated: boolean;
    /** Whether RAYXO put it on the network interface. */
    added: boolean;
    note: string | null;
    /** The accounts whose websites are served on it. */
    accounts: string[];
};

type ServerAddress = {
    address: string;
    prefix: number;
    interface: string;
    added: boolean;
    primary: boolean;
};

type Reseller = { id: number; username: string };

/** The select's value for "the administrators"; Radix items cannot be empty. */
const administrators = 'admin';

/**
 * The server's extra IP addresses: administrators add them, give them to
 * resellers and remove them; the owner marks them dedicated or shared.
 * Accounts get them in the account list (Alamat IP).
 */
export default function IpAddressesIndex({
    scope,
    mainAddress,
    addresses,
    serverAddresses,
    resellers,
}: {
    scope: AccountScope;
    mainAddress: string | null;
    addresses: IpAddressRow[];
    serverAddresses?: ServerAddress[] | null;
    resellers: Reseller[];
}) {
    const isAdmin = scope === 'admin';
    const routes = isAdmin ? adminIpAddresses : resellerIpAddresses;
    const [editing, setEditing] = useState<IpAddressRow | null>(null);
    const [removing, setRemoving] = useState<IpAddressRow | null>(null);

    return (
        <>
            <Head title="Alamat IP" />

            <PageHeader
                title="Alamat IP"
                description={
                    isAdmin
                        ? `Alamat IP tambahan server untuk website akun. Akun tanpa alamat sendiri memakai alamat utama server${mainAddress ? ` (${mainAddress})` : ''}.`
                        : 'Alamat IP yang diberikan admin kepada Anda. Berikan ke akun lewat menu Alamat IP di daftar akun.'
                }
            />

            {isAdmin && (
                <Panel
                    title="Tambah alamat"
                    description="Alamat yang belum ada di server ditambahkan RAYXO ke interface jaringan utama dan dipasang lagi setelah reboot. Pastikan provider VPS sudah mengarahkan alamat itu ke server ini."
                >
                    <Form
                        {...adminIpAddresses.store.form()}
                        options={{ preserveScroll: true }}
                        resetOnSuccess
                        className="grid gap-4 md:grid-cols-[2fr_1fr_2fr_auto] md:items-start"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="address">Alamat IP</Label>
                                    <Input
                                        id="address"
                                        name="address"
                                        required
                                        autoComplete="off"
                                        maxLength={45}
                                        placeholder="mis. 203.0.113.10 atau 2001:db8::10"
                                        className="font-mono"
                                    />
                                    <InputError message={errors.address} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="prefix">
                                        Prefix (opsional)
                                    </Label>
                                    <Input
                                        id="prefix"
                                        name="prefix"
                                        type="number"
                                        inputMode="numeric"
                                        min={1}
                                        max={128}
                                        placeholder="32 / 128"
                                    />
                                    <InputError message={errors.prefix} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="note">
                                        Catatan (opsional)
                                    </Label>
                                    <Input
                                        id="note"
                                        name="note"
                                        maxLength={255}
                                        autoComplete="off"
                                    />
                                    <InputError message={errors.note} />
                                </div>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    className="md:mt-7"
                                >
                                    {processing ? <Spinner /> : <Plus />}
                                    Tambah
                                </Button>
                            </>
                        )}
                    </Form>
                </Panel>
            )}

            <Panel
                flush
                title="Alamat"
                description={`${addresses.length} alamat`}
            >
                {addresses.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 px-6 pt-4 pb-10 text-center">
                        <span className="flex size-14 items-center justify-center rounded-full bg-accent text-accent-foreground">
                            <Network className="size-6" />
                        </span>
                        <p className="text-sm text-muted-foreground">
                            {isAdmin
                                ? 'Belum ada alamat tambahan. Semua akun memakai alamat utama server.'
                                : 'Admin belum memberi Anda alamat IP.'}
                        </p>
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className={tableStyles.table}>
                            <thead className={tableStyles.head}>
                                <tr>
                                    <th className={tableStyles.th}>Alamat</th>
                                    {isAdmin && (
                                        <th className={tableStyles.th}>
                                            Milik
                                        </th>
                                    )}
                                    <th className={tableStyles.th}>Jenis</th>
                                    <th className={tableStyles.th}>
                                        Dipakai akun
                                    </th>
                                    <th className={tableStyles.th}>Catatan</th>
                                    <th className={tableStyles.th}>
                                        <span className="sr-only">Aksi</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {addresses.map((row) => (
                                    <tr
                                        key={row.id}
                                        className={`${tableStyles.row} align-top`}
                                    >
                                        <td className={tableStyles.td}>
                                            <span className="font-mono text-sm">
                                                {row.address}/{row.prefix}
                                            </span>
                                            <p className="text-xs text-muted-foreground">
                                                IPv{row.version}
                                                {!row.added &&
                                                    ' · sudah ada di server'}
                                            </p>
                                        </td>
                                        {isAdmin && (
                                            <td className={tableStyles.td}>
                                                {row.owner?.username ?? 'Admin'}
                                            </td>
                                        )}
                                        <td className={tableStyles.td}>
                                            {row.dedicated
                                                ? 'Dedicated (satu akun)'
                                                : 'Shared'}
                                        </td>
                                        <td className={tableStyles.td}>
                                            {row.accounts.length > 0 ? (
                                                row.accounts.join(', ')
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    —
                                                </span>
                                            )}
                                        </td>
                                        <td
                                            className={`${tableStyles.td} text-muted-foreground`}
                                        >
                                            {row.note ?? '—'}
                                        </td>
                                        <td className={tableStyles.td}>
                                            <div className="flex justify-end gap-1">
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={`Ubah ${row.address}`}
                                                    onClick={() =>
                                                        setEditing(row)
                                                    }
                                                >
                                                    <Pencil />
                                                </Button>
                                                {isAdmin && (
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        aria-label={`Hapus ${row.address}`}
                                                        disabled={
                                                            row.accounts
                                                                .length > 0
                                                        }
                                                        onClick={() =>
                                                            setRemoving(row)
                                                        }
                                                    >
                                                        <Trash2 />
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

            {isAdmin && (
                <ServerAddresses
                    serverAddresses={serverAddresses}
                    registered={addresses.map((row) => row.address)}
                />
            )}

            <Dialog
                open={editing !== null}
                onOpenChange={(open) => !open && setEditing(null)}
            >
                <DialogContent className="rounded-2xl">
                    {editing && (
                        <EditForm
                            key={editing.id}
                            row={editing}
                            routes={routes}
                            resellers={isAdmin ? resellers : null}
                            onSuccess={() => setEditing(null)}
                        />
                    )}
                </DialogContent>
            </Dialog>

            <Dialog
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
            >
                <DialogContent className="rounded-2xl">
                    {removing && (
                        <Form
                            {...adminIpAddresses.destroy.form(removing.id)}
                            options={{ preserveScroll: true }}
                            onSuccess={() => setRemoving(null)}
                            className="grid gap-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <DialogHeader>
                                        <DialogTitle>
                                            Hapus {removing.address}?
                                        </DialogTitle>
                                        <DialogDescription>
                                            {removing.added
                                                ? 'Alamat ini dilepas dari interface jaringan server.'
                                                : 'Alamat ini tetap ada di server, hanya tidak bisa diberikan ke akun lagi.'}
                                        </DialogDescription>
                                    </DialogHeader>
                                    <InputError message={errors.address} />
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
                                            Hapus alamat
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

/**
 * Changes who an address belongs to (administrators only), whether it is
 * dedicated, and its note.
 */
function EditForm({
    row,
    routes,
    resellers,
    onSuccess,
}: {
    row: IpAddressRow;
    routes: typeof adminIpAddresses | typeof resellerIpAddresses;
    resellers: Reseller[] | null;
    onSuccess: () => void;
}) {
    const [owner, setOwner] = useState(
        row.owner === null ? administrators : String(row.owner.id),
    );
    const [dedicated, setDedicated] = useState(row.dedicated);

    return (
        <Form
            {...routes.update.form(row.id)}
            options={{ preserveScroll: true }}
            onSuccess={onSuccess}
            className="grid gap-4"
        >
            {({ processing, errors }) => (
                <>
                    <DialogHeader>
                        <DialogTitle>Ubah {row.address}</DialogTitle>
                        <DialogDescription>
                            Alamat dedicated hanya untuk satu akun; alamat
                            shared bisa dipakai beberapa akun.
                        </DialogDescription>
                    </DialogHeader>

                    {resellers !== null && (
                        <div className="grid gap-2">
                            <Label htmlFor="owner_id">Milik</Label>
                            <input
                                type="hidden"
                                name="owner_id"
                                value={owner === administrators ? '' : owner}
                            />
                            <Select value={owner} onValueChange={setOwner}>
                                <SelectTrigger
                                    id="owner_id"
                                    className="w-full rounded-xl bg-card px-3.5 data-[size=default]:h-11"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent className="rounded-xl">
                                    <SelectItem value={administrators}>
                                        Admin (untuk akun milik admin)
                                    </SelectItem>
                                    {resellers.map((reseller) => (
                                        <SelectItem
                                            key={reseller.id}
                                            value={String(reseller.id)}
                                        >
                                            Reseller {reseller.username}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <p className="text-xs text-muted-foreground">
                                Pemilik hanya bisa diganti selama belum ada akun
                                yang memakai alamat ini.
                            </p>
                            <InputError message={errors.owner_id} />
                        </div>
                    )}

                    <div>
                        <input
                            type="hidden"
                            name="dedicated"
                            value={dedicated ? 1 : 0}
                        />
                        <div className="flex items-start gap-3">
                            <Checkbox
                                id="dedicated"
                                checked={dedicated}
                                onCheckedChange={(checked) =>
                                    setDedicated(checked === true)
                                }
                                className="mt-0.5"
                            />
                            <div className="grid gap-1">
                                <Label htmlFor="dedicated">Dedicated</Label>
                                <p className="text-xs text-muted-foreground">
                                    Hanya satu akun yang boleh memakai alamat
                                    ini.
                                </p>
                            </div>
                        </div>
                        <InputError message={errors.dedicated} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="edit-note">Catatan</Label>
                        <Input
                            id="edit-note"
                            name="note"
                            maxLength={255}
                            defaultValue={row.note ?? ''}
                        />
                        <InputError message={errors.note} />
                    </div>

                    <InputError message={errors.address} />

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

/**
 * The global addresses the server's interfaces have, as the agent reports
 * them; loaded after the page.
 */
function ServerAddresses({
    serverAddresses,
    registered,
}: {
    serverAddresses?: ServerAddress[] | null;
    registered: string[];
}) {
    return (
        <Panel
            flush
            title="Alamat di server"
            description="Yang terpasang di interface jaringan server saat ini. Alamat utama tidak bisa diberikan ke akun: website lain dilayani di sana."
        >
            {serverAddresses === undefined ? (
                <div className="grid animate-pulse gap-3 px-6 pt-2 pb-6">
                    <div className="h-4 w-1/2 rounded bg-muted" />
                    <div className="h-4 w-1/3 rounded bg-muted" />
                </div>
            ) : serverAddresses === null ? (
                <p className="px-6 pb-6 text-sm text-muted-foreground">
                    Agent tidak bisa dihubungi, jadi alamat server tidak bisa
                    ditampilkan.
                </p>
            ) : (
                <div className="overflow-x-auto">
                    <table className={tableStyles.table}>
                        <thead className={tableStyles.head}>
                            <tr>
                                <th className={tableStyles.th}>Alamat</th>
                                <th className={tableStyles.th}>Interface</th>
                                <th className={tableStyles.th}>Keterangan</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {serverAddresses.map((address) => (
                                <tr
                                    key={address.address}
                                    className={tableStyles.row}
                                >
                                    <td
                                        className={`${tableStyles.td} font-mono text-sm`}
                                    >
                                        {address.address}/{address.prefix}
                                    </td>
                                    <td className={tableStyles.td}>
                                        {address.interface}
                                    </td>
                                    <td
                                        className={`${tableStyles.td} text-muted-foreground`}
                                    >
                                        {[
                                            address.primary &&
                                                !address.added &&
                                                'alamat utama',
                                            address.added &&
                                                'ditambahkan RAYXO',
                                            registered.includes(
                                                address.address,
                                            ) && 'terdaftar di panel',
                                        ]
                                            .filter(Boolean)
                                            .join(' · ') || '—'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </Panel>
    );
}
