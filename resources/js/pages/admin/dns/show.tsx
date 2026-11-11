import { Head, Link, usePoll } from '@inertiajs/react';
import { ArrowLeft, Plus, RotateCcw } from 'lucide-react';
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
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { index, reset as resetZone } from '@/routes/admin/dns';
import { destroy, store, update } from '@/routes/admin/dns/records';
import type { AccountStatus } from '@/types';

type Props = {
    zone: {
        id: number;
        name: string;
        account: string;
        account_status: AccountStatus;
    };
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

export default function AdminDnsZone({
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

    return (
        <>
            <Head title={`DNS ${zone.name}`} />

            <div>
                <Button asChild variant="ghost" size="sm">
                    <Link href={index()}>
                        <ArrowLeft /> Semua zone
                    </Link>
                </Button>
            </div>

            <PageHeader
                title={zone.name}
                description={`Zone milik akun ${zone.account}. Nameserver: ${nameservers.join(', ')}.`}
                actions={
                    <Button
                        size="lg"
                        onClick={() => openDialog({ type: 'create' })}
                        disabled={records.length >= max_records}
                    >
                        <Plus /> Tambah record
                    </Button>
                }
            />

            {zone.account_status !== 'active' && (
                <p className="flex items-center gap-2 text-sm text-muted-foreground">
                    <StatusBadge status={zone.account_status} /> Akun ini tidak
                    aktif; admin tetap bisa mengubah record selama akun
                    di-suspend.
                </p>
            )}

            <DnsSyncState sync={sync} />

            <Panel
                title="Record"
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
                                    </span>{' '}
                                    milik {zone.account}.
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
                            description="Semua record diganti dengan template dan record subdomain. Record yang ditambahkan sendiri hilang."
                            confirm="Reset zone"
                            onSuccess={closeDialog}
                        />
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}
