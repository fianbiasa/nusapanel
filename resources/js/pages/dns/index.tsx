import { Head, router, usePoll } from '@inertiajs/react';
import { Network, Plus, RotateCcw } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { DnsRecordRow } from '@/components/dns-records';
import {
    DnsConfirmForm,
    DnsRecordForm,
    DnsRecordsTable,
    DnsSyncState,
} from '@/components/dns-records';
import { PageHeader } from '@/components/page-header';
import { Panel } from '@/components/panel';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { DomainSwitcher } from '@/components/domain-switcher';
import { index as dnsIndex, reset as resetZone } from '@/routes/dns';
import { destroy, store, update } from '@/routes/dns/records';

type Zone = { id: number; name: string };

type Props = {
    zones: Zone[];
    zone: Zone | null;
    records: DnsRecordRow[];
    nameservers: string[];
    types: string[];
    max_records: number;
    sync: { pending: boolean; error: string | null };
};

type Action =
    | { type: 'create' }
    | { type: 'edit'; record: DnsRecordRow }
    | { type: 'delete'; record: DnsRecordRow }
    | { type: 'reset' };

export default function DnsIndex({
    zones,
    zone,
    records,
    nameservers,
    types,
    max_records,
    sync,
}: Props) {
    const [action, setAction] = useState<Action | null>(null);
    const [dialogOpen, setDialogOpen] = useState(false);

    const openDialog = (next: Action) => {
        setAction(next);
        setDialogOpen(true);
    };

    const closeDialog = () => setDialogOpen(false);

    // Refresh while the agent is still writing the zone.
    const { start, stop } = usePoll(
        3000,
        { only: ['sync'] },
        { autoStart: false },
    );

    useEffect(() => {
        if (sync.pending) {
            start();
        } else {
            stop();
        }
    }, [sync.pending, start, stop]);

    const chooseZone = (id: string) =>
        router.get(
            dnsIndex.url({ query: { zone: id } }),
            {},
            { preserveScroll: true },
        );

    return (
        <>
            <Head title="DNS" />

            <PageHeader
                title="DNS"
                description="Record DNS domain kamu di server ini: alamat website, email, dan verifikasi layanan lain."
                actions={
                    zone !== null && (
                        <Button
                            size="lg"
                            onClick={() => openDialog({ type: 'create' })}
                            disabled={records.length >= max_records}
                        >
                            <Plus /> Tambah record
                        </Button>
                    )
                }
            />

            {zone !== null && zones.length > 1 && (
                <DomainSwitcher
                    options={zones.map((option) => ({
                        value: String(option.id),
                        label: option.name,
                    }))}
                    value={String(zone.id)}
                    onChange={chooseZone}
                />
            )}

            <DnsSyncState sync={sync} />

            {zone === null ? (
                <Panel title="Belum ada zone DNS">
                    <div className="flex flex-col items-center gap-3 px-6 pt-4 pb-10 text-center">
                        <span className="flex size-14 items-center justify-center rounded-full bg-accent text-accent-foreground">
                            <Network className="size-6" />
                        </span>
                        <p className="max-w-sm text-sm text-muted-foreground">
                            Zone DNS dibuat otomatis saat kamu menambahkan
                            domain di halaman Domains.
                        </p>
                    </div>
                </Panel>
            ) : (
                <>
                    <Panel
                        title="Nameserver"
                        description={`Record di bawah hanya dipakai kalau nameserver ${zone.name} di registrar domain mengarah ke server ini.`}
                    >
                        <div className="grid gap-3 text-sm">
                            <ul className="grid gap-1 font-mono font-semibold">
                                {nameservers.map((nameserver) => (
                                    <li key={nameserver} className="break-all">
                                        {nameserver}
                                    </li>
                                ))}
                            </ul>
                            <p className="text-muted-foreground">
                                Kalau domain memakai DNS lain, misalnya
                                Cloudflare, atur record-nya di sana; record di
                                sini tidak dipakai.
                            </p>
                        </div>
                    </Panel>

                    <Panel
                        title={`Record ${zone.name}`}
                        description={`${records.length} dari ${max_records} record`}
                        action={
                            <Button
                                variant="outline"
                                onClick={() => openDialog({ type: 'reset' })}
                            >
                                <RotateCcw /> Reset
                            </Button>
                        }
                        flush
                    >
                        {records.length === 0 ? (
                            <p className="px-6 pt-2 pb-8 text-sm text-muted-foreground">
                                Zone ini belum punya record.
                            </p>
                        ) : (
                            <DnsRecordsTable
                                records={records}
                                onEdit={(record) =>
                                    openDialog({ type: 'edit', record })
                                }
                                onDelete={(record) =>
                                    openDialog({ type: 'delete', record })
                                }
                            />
                        )}
                    </Panel>
                </>
            )}

            {zone !== null && (
                <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                    <DialogContent className="rounded-2xl sm:max-w-xl">
                        {(action?.type === 'create' ||
                            action?.type === 'edit') && (
                            <DnsRecordForm
                                key={
                                    action.type === 'edit'
                                        ? `edit-${action.record.id}`
                                        : 'create'
                                }
                                form={
                                    action.type === 'edit'
                                        ? update.form(action.record.id)
                                        : store.form(zone.id)
                                }
                                title={
                                    action.type === 'edit'
                                        ? 'Ubah record'
                                        : 'Tambah record'
                                }
                                description={
                                    <>
                                        Di zone{' '}
                                        <span className="font-mono">
                                            {zone.name}
                                        </span>
                                        .
                                    </>
                                }
                                suffix={`.${zone.name}`}
                                nameHelp={`Kosongkan atau isi @ untuk ${zone.name} sendiri.`}
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
                                form={destroy.form(action.record.id)}
                                title={`Hapus record ${action.record.type} ${action.record.name}?`}
                                description={`Record ini hilang dari zone ${zone.name}.`}
                                confirm="Hapus record"
                                onSuccess={closeDialog}
                            />
                        )}
                        {action?.type === 'reset' && (
                            <DnsConfirmForm
                                key="reset"
                                form={resetZone.form(zone.id)}
                                title={`Reset zone ${zone.name}?`}
                                description="Semua record diganti dengan record bawaan dan record subdomain. Record yang kamu tambahkan sendiri hilang."
                                confirm="Reset zone"
                                onSuccess={closeDialog}
                            />
                        )}
                    </DialogContent>
                </Dialog>
            )}
        </>
    );
}
