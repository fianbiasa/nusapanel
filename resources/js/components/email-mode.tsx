import { Form } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { useState } from 'react';
import type { MailDomainOption } from '@/components/email-forwarders';
import InputError from '@/components/input-error';
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
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { update as updateMode } from '@/routes/email/mode';

/** Who takes a domain's mail: this server, or one of the other services. */
type Receiver = 'local' | 'google' | 'zoho' | 'other';

const receivers: Record<Receiver, { label: string; explanation: string }> = {
    local: {
        label: 'Server ini',
        explanation:
            'Email ke domain ini masuk ke akun email di server ini. Kalau record MX-nya diubah panel untuk Google Workspace atau Zoho, record itu dikembalikan ke mail.<domain>.',
    },
    google: {
        label: 'Google Workspace',
        explanation:
            'Record MX diganti ke smtp.google.com dan SPF ditambah include:_spf.google.com. Record DKIM Google ambil dari Admin Console Google, lalu tambahkan di halaman DNS.',
    },
    zoho: {
        label: 'Zoho Mail',
        explanation:
            'Record MX diganti ke mx.zoho.com, mx2.zoho.com dan mx3.zoho.com, dan SPF ditambah include:zohomail.com. Akun Zoho di pusat data lain (misalnya zoho.eu) pilih Layanan lain.',
    },
    other: {
        label: 'Layanan lain',
        explanation:
            'Panel tidak memasang record apa pun, dan melepas record MX dan SPF Google Workspace atau Zoho yang dulu dipasangnya. Atur record MX dan SPF sesuai petunjuk layanan email Anda di halaman DNS.',
    },
};

const triggerClass =
    'w-full rounded-xl bg-card px-3.5 data-[size=default]:h-11';

function receiverOf(domain: MailDomainOption): Receiver {
    return domain.mail_mode === 'external'
        ? (domain.mail_provider ?? 'other')
        : 'local';
}

/**
 * Who takes each domain's mail: this server, or another service such as
 * Google Workspace or Zoho Mail. For those two the panel sets the domain's
 * MX and SPF records; the domain's mailboxes can still sign in, so old mail
 * can be moved.
 */
export function MailModePanel({ domains }: { domains: MailDomainOption[] }) {
    const [editing, setEditing] = useState<MailDomainOption | null>(null);
    const [dialogOpen, setDialogOpen] = useState(false);

    if (domains.length === 0) {
        return null;
    }

    return (
        <Panel
            title="Penerima email"
            description="Siapa yang menerima email tiap domain: server ini, atau layanan lain seperti Google Workspace atau Zoho Mail."
            flush
        >
            <div className="overflow-x-auto">
                <table className={tableStyles.table}>
                    <thead className={tableStyles.head}>
                        <tr>
                            <th className={tableStyles.th}>Domain</th>
                            <th className={tableStyles.th}>Diterima oleh</th>
                            <th className={tableStyles.th}>
                                <span className="sr-only">Aksi</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {domains.map((domain) => (
                            <tr key={domain.id} className={tableStyles.row}>
                                <td
                                    className={`${tableStyles.td} font-mono font-semibold break-all`}
                                >
                                    {domain.name}
                                </td>
                                <td
                                    className={`${tableStyles.td} text-sm text-muted-foreground`}
                                >
                                    {receivers[receiverOf(domain)].label}
                                </td>
                                <td className={tableStyles.td}>
                                    <div className="flex justify-end">
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            aria-label={`Ubah penerima email ${domain.name}`}
                                            onClick={() => {
                                                setEditing(domain);
                                                setDialogOpen(true);
                                            }}
                                        >
                                            <Pencil />
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                <DialogContent className="rounded-2xl">
                    {editing !== null && (
                        <MailModeForm
                            key={`mail-mode-${editing.id}`}
                            domain={editing}
                            onSuccess={() => setDialogOpen(false)}
                        />
                    )}
                </DialogContent>
            </Dialog>
        </Panel>
    );
}

function MailModeForm({
    domain,
    onSuccess,
}: {
    domain: MailDomainOption;
    onSuccess: () => void;
}) {
    const [receiver, setReceiver] = useState<Receiver>(receiverOf(domain));

    return (
        <Form
            {...updateMode.form(domain.id)}
            options={{ preserveScroll: true, preserveState: true }}
            onSuccess={onSuccess}
            className="grid gap-5"
        >
            {({ processing, errors }) => (
                <>
                    <DialogHeader>
                        <DialogTitle>Penerima email {domain.name}</DialogTitle>
                        <DialogDescription>
                            Email dari website dan akun di server ini ke domain
                            ini ikut dikirim ke penerima yang dipilih.
                        </DialogDescription>
                    </DialogHeader>

                    <input
                        type="hidden"
                        name="mode"
                        value={receiver === 'local' ? 'local' : 'external'}
                    />
                    {receiver !== 'local' && (
                        <input type="hidden" name="provider" value={receiver} />
                    )}

                    <div className="grid gap-2">
                        <Label htmlFor="mail-receiver">Diterima oleh</Label>
                        <Select
                            value={receiver}
                            onValueChange={(value) =>
                                setReceiver(value as Receiver)
                            }
                        >
                            <SelectTrigger
                                id="mail-receiver"
                                className={triggerClass}
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent className="rounded-xl">
                                {Object.entries(receivers).map(
                                    ([value, { label }]) => (
                                        <SelectItem key={value} value={value}>
                                            {label}
                                        </SelectItem>
                                    ),
                                )}
                            </SelectContent>
                        </Select>
                        <p className="text-sm text-muted-foreground">
                            {receivers[receiver].explanation.replace(
                                '<domain>',
                                domain.name,
                            )}
                        </p>
                        {receiver !== 'local' && (
                            <p className="text-sm text-muted-foreground">
                                Akun email domain ini yang sudah ada tetap bisa
                                login (IMAP dan webmail) untuk memindahkan email
                                lama, tapi tidak menerima email baru; forwarder
                                dan catch-all-nya tidak berlaku. Kalau DNS
                                domain ini dikelola di luar server ini (misalnya
                                Cloudflare), ubah record-nya di sana.
                            </p>
                        )}
                        <InputError message={errors.mode ?? errors.provider} />
                    </div>

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
