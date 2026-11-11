import { Form, Head, Link } from '@inertiajs/react';
import { Network, Plus, RotateCcw, Search } from 'lucide-react';
import { useState } from 'react';
import type { DnsRecordRow } from '@/components/dns-records';
import {
    DnsConfirmForm,
    DnsRecordForm,
    DnsRecordsTable,
} from '@/components/dns-records';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { Panel, tableStyles } from '@/components/panel';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { index } from '@/routes/admin/dns';
import {
    destroy as destroyTemplate,
    reset as resetTemplate,
    store as storeTemplate,
    update as updateTemplate,
} from '@/routes/admin/dns/template';
import { show as showZone } from '@/routes/admin/dns/zones';
import type { AccountStatus, Paginated } from '@/types';

type ZoneRow = {
    id: number;
    name: string;
    account: string;
    account_status: AccountStatus;
    records: number;
};

type Props = {
    zones: Paginated<ZoneRow>;
    filters: { search: string };
    template: DnsRecordRow[];
    nameservers: string[];
    types: string[];
};

type Action =
    | { type: 'create' }
    | { type: 'edit'; record: DnsRecordRow }
    | { type: 'delete'; record: DnsRecordRow }
    | { type: 'reset' };

export default function AdminDnsIndex({
    zones,
    filters,
    template,
    nameservers,
    types,
}: Props) {
    const [action, setAction] = useState<Action | null>(null);
    const [dialogOpen, setDialogOpen] = useState(false);

    const openDialog = (next: Action) => {
        setAction(next);
        setDialogOpen(true);
    };

    const closeDialog = () => setDialogOpen(false);

    return (
        <>
            <Head title="DNS" />

            <PageHeader
                title="DNS"
                description={`Semua zone DNS di server ini dan template zone baru. Nameserver: ${nameservers.join(', ')}.`}
            />

            <Panel
                flush
                title="Zone"
                description={`${zones.total} zone`}
                action={
                    <Form
                        {...index.form()}
                        options={{ preserveState: true, replace: true }}
                        className="flex w-full max-w-sm items-center gap-2"
                    >
                        <div className="relative flex-1">
                            <Search className="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                type="search"
                                name="search"
                                defaultValue={filters.search}
                                placeholder="Cari zone atau username"
                                aria-label="Cari zone"
                                maxLength={100}
                                className="h-10 pl-10"
                            />
                        </div>
                        <Button type="submit" variant="outline">
                            Cari
                        </Button>
                    </Form>
                }
            >
                {zones.data.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 px-6 pt-4 pb-10 text-center">
                        <span className="flex size-14 items-center justify-center rounded-full bg-accent text-accent-foreground">
                            <Network className="size-6" />
                        </span>
                        <p className="max-w-sm text-sm text-muted-foreground">
                            {filters.search
                                ? `Tidak ada zone yang cocok dengan "${filters.search}".`
                                : 'Belum ada zone. Zone dibuat otomatis saat domain ditambahkan.'}
                        </p>
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className={tableStyles.table}>
                            <thead className={tableStyles.head}>
                                <tr>
                                    <th className={tableStyles.th}>Zone</th>
                                    <th className={tableStyles.th}>Akun</th>
                                    <th className={tableStyles.th}>Record</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {zones.data.map((zone) => (
                                    <tr
                                        key={zone.id}
                                        className={tableStyles.row}
                                    >
                                        <td className={tableStyles.td}>
                                            <Link
                                                href={showZone(zone.id)}
                                                className="font-mono font-semibold break-all text-primary hover:underline"
                                            >
                                                {zone.name}
                                            </Link>
                                        </td>
                                        <td className={tableStyles.td}>
                                            <div className="flex flex-wrap items-center gap-2">
                                                <code className="font-mono">
                                                    {zone.account}
                                                </code>
                                                {zone.account_status !==
                                                    'active' && (
                                                    <StatusBadge
                                                        status={
                                                            zone.account_status
                                                        }
                                                    />
                                                )}
                                            </div>
                                        </td>
                                        <td
                                            className={`${tableStyles.td} text-muted-foreground`}
                                        >
                                            {zone.records}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {zones.last_page > 1 && (
                    <div className="border-t px-6 py-4">
                        <Pagination page={zones} />
                    </div>
                )}
            </Panel>

            <Panel
                flush
                title="Template zone baru"
                description="Record yang dimiliki setiap zone baru dan zone yang di-reset. {domain} diganti nama domain, {ip} alamat IP server. Zone yang sudah ada tidak berubah."
                action={
                    <div className="flex flex-wrap gap-2">
                        <Button
                            variant="outline"
                            onClick={() => openDialog({ type: 'reset' })}
                        >
                            <RotateCcw /> Kembalikan bawaan
                        </Button>
                        <Button onClick={() => openDialog({ type: 'create' })}>
                            <Plus /> Tambah record
                        </Button>
                    </div>
                }
            >
                {template.length === 0 ? (
                    <p className="px-6 pt-2 pb-8 text-sm text-muted-foreground">
                        Template kosong: zone baru tidak punya record selain
                        nameserver.
                    </p>
                ) : (
                    <DnsRecordsTable
                        records={template}
                        onEdit={(record) =>
                            openDialog({ type: 'edit', record })
                        }
                        onDelete={(record) =>
                            openDialog({ type: 'delete', record })
                        }
                    />
                )}
            </Panel>

            <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                <DialogContent className="rounded-2xl sm:max-w-xl">
                    {(action?.type === 'create' || action?.type === 'edit') && (
                        <DnsRecordForm
                            key={
                                action.type === 'edit'
                                    ? `edit-${action.record.id}`
                                    : 'create'
                            }
                            form={
                                action.type === 'edit'
                                    ? updateTemplate.form(action.record.id)
                                    : storeTemplate.form()
                            }
                            title={
                                action.type === 'edit'
                                    ? 'Ubah record template'
                                    : 'Tambah record template'
                            }
                            description="Berlaku untuk zone baru dan zone yang di-reset."
                            suffix=".{domain}"
                            nameHelp="Kosongkan atau isi @ untuk domain itu sendiri."
                            contentHelp="Pakai {domain} untuk nama domain dan {ip} untuk alamat IP server."
                            types={types}
                            record={
                                action.type === 'edit'
                                    ? action.record
                                    : undefined
                            }
                            onSuccess={closeDialog}
                        />
                    )}
                    {action?.type === 'delete' && (
                        <DnsConfirmForm
                            key={`delete-${action.record.id}`}
                            form={destroyTemplate.form(action.record.id)}
                            title={`Hapus ${action.record.type} ${action.record.name} dari template?`}
                            description="Zone baru tidak lagi mendapat record ini. Zone yang sudah ada tidak berubah."
                            confirm="Hapus dari template"
                            onSuccess={closeDialog}
                        />
                    )}
                    {action?.type === 'reset' && (
                        <DnsConfirmForm
                            key="reset"
                            form={resetTemplate.form()}
                            title="Kembalikan template bawaan?"
                            description="Template kembali ke bawaan RAYXO: A untuk @, www, mail dan ftp, MX ke mail, dan SPF. Perubahan template hilang; zone yang sudah ada tidak berubah."
                            confirm="Kembalikan bawaan"
                            onSuccess={closeDialog}
                        />
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}
