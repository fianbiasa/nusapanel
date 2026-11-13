import { Plus, RotateCcw } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import type { DnsRecordRow } from '@/components/dns-records';
import {
    DnsConfirmForm,
    DnsRecordForm,
    DnsRecordsTable,
} from '@/components/dns-records';
import { Panel } from '@/components/panel';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import type { RouteFormDefinition } from '@/wayfinder';

type Action =
    | { type: 'create' }
    | { type: 'edit'; record: DnsRecordRow }
    | { type: 'delete'; record: DnsRecordRow }
    | { type: 'reset' };

/** The routes that change one template. */
export type DnsTemplateRoutes = {
    store: { form: () => RouteFormDefinition<'post'> };
    update: { form: (id: number) => RouteFormDefinition<'post'> };
    destroy: { form: (id: number) => RouteFormDefinition<'post'> };
    reset: { form: () => RouteFormDefinition<'post'> };
};

/**
 * The records new DNS zones start with, and the dialogs that change them:
 * the administrators' template, or a reseller's own. Without routes the
 * template is only shown.
 */
export function DnsTemplatePanel({
    title,
    description,
    template,
    types,
    routes,
    reset,
    actions,
}: {
    title: string;
    description: string;
    template: DnsRecordRow[];
    types: string[];
    routes: DnsTemplateRoutes | null;
    /** What resetting does, in the panel's words. */
    reset: { label: string; title: string; description: string };
    /** More buttons next to the panel's own. */
    actions?: ReactNode;
}) {
    const [action, setAction] = useState<Action | null>(null);
    const [dialogOpen, setDialogOpen] = useState(false);

    const openDialog = (next: Action) => {
        setAction(next);
        setDialogOpen(true);
    };

    const closeDialog = () => setDialogOpen(false);

    return (
        <>
            <Panel
                flush
                title={title}
                description={description}
                action={
                    <div className="flex flex-wrap gap-2">
                        {actions}
                        {routes && (
                            <>
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        openDialog({ type: 'reset' })
                                    }
                                >
                                    <RotateCcw /> {reset.label}
                                </Button>
                                <Button
                                    onClick={() =>
                                        openDialog({ type: 'create' })
                                    }
                                >
                                    <Plus /> Tambah record
                                </Button>
                            </>
                        )}
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
                        onEdit={
                            routes
                                ? (record) =>
                                      openDialog({ type: 'edit', record })
                                : undefined
                        }
                        onDelete={
                            routes
                                ? (record) =>
                                      openDialog({ type: 'delete', record })
                                : undefined
                        }
                    />
                )}
            </Panel>

            {routes && (
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
                                        ? routes.update.form(action.record.id)
                                        : routes.store.form()
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
                                form={routes.destroy.form(action.record.id)}
                                title={`Hapus ${action.record.type} ${action.record.name} dari template?`}
                                description="Zone baru tidak lagi mendapat record ini. Zone yang sudah ada tidak berubah."
                                confirm="Hapus dari template"
                                onSuccess={closeDialog}
                            />
                        )}
                        {action?.type === 'reset' && (
                            <DnsConfirmForm
                                key="reset"
                                form={routes.reset.form()}
                                title={reset.title}
                                description={reset.description}
                                confirm={reset.label}
                                onSuccess={closeDialog}
                            />
                        )}
                    </DialogContent>
                </Dialog>
            )}
        </>
    );
}
