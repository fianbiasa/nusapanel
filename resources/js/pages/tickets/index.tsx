import { Form, Head, Link, router } from '@inertiajs/react';
import { LifeBuoy, Plus } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { Panel, tableStyles } from '@/components/panel';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
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
import { formatRelative } from '@/lib/format';
import { ticketPriority, ticketStatus } from '@/lib/tickets';
import { index, show, store } from '@/routes/tickets';
import type { Paginated } from '@/types';

type Row = {
    id: number;
    subject: string;
    status: string;
    priority: string;
    from: { username: string; name: string };
    last_message_at: string | null;
};

type Props = {
    box: 'mine' | 'inbox';
    status: string | null;
    tickets: Paginated<Row>;
    answers: boolean;
    can_open: boolean;
    recipient: 'reseller' | 'admin' | null;
    waiting: { mine: number; inbox: number };
    priorities: string[];
    max_body: number;
};

const textareaClass =
    'min-h-40 w-full rounded-xl border border-input bg-card px-3.5 py-2.5 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive aria-invalid:ring-destructive/20';

export default function Tickets({
    box,
    status,
    tickets,
    answers,
    can_open,
    recipient,
    waiting,
    priorities,
    max_body,
}: Props) {
    const [creating, setCreating] = useState(false);
    const [priority, setPriority] = useState('normal');

    const visit = (next: { box?: string; status?: string | null }) =>
        router.get(
            index.url(),
            {
                box: next.box ?? box,
                ...((next.status ?? null) ? { status: next.status } : {}),
            },
            { preserveScroll: true },
        );

    return (
        <>
            <Head title="Tiket" />

            <PageHeader
                title="Tiket"
                description={
                    recipient === 'reseller'
                        ? 'Tanyakan atau laporkan masalah ke penyedia hosting kamu.'
                        : recipient === 'admin'
                          ? 'Tanyakan atau laporkan masalah ke administrator server.'
                          : 'Tiket dari reseller dan akun hosting yang dibuat administrator.'
                }
                actions={
                    can_open && (
                        <Button onClick={() => setCreating(true)}>
                            <Plus /> Buat tiket
                        </Button>
                    )
                }
            />

            <div className="flex flex-wrap items-center gap-2">
                {answers && (
                    <>
                        <Button
                            size="sm"
                            variant={box === 'inbox' ? 'default' : 'outline'}
                            onClick={() =>
                                visit({ box: 'inbox', status: null })
                            }
                        >
                            Masuk
                            {waiting.inbox > 0 &&
                                ` (${waiting.inbox} menunggu)`}
                        </Button>
                        {can_open && (
                            <Button
                                size="sm"
                                variant={box === 'mine' ? 'default' : 'outline'}
                                onClick={() =>
                                    visit({ box: 'mine', status: null })
                                }
                            >
                                Tiket saya
                                {waiting.mine > 0 &&
                                    ` (${waiting.mine} dijawab)`}
                            </Button>
                        )}
                        <span className="mx-1 h-5 w-px bg-border" />
                    </>
                )}
                {[null, 'open', 'answered', 'closed'].map((option) => (
                    <Button
                        key={option ?? 'all'}
                        size="sm"
                        variant={status === option ? 'secondary' : 'ghost'}
                        onClick={() => visit({ status: option })}
                    >
                        {option === null ? 'Semua' : ticketStatus[option].label}
                    </Button>
                ))}
            </div>

            <Panel flush>
                {tickets.data.length === 0 ? (
                    <div className="flex flex-col items-center gap-2 px-6 py-12 text-center text-sm text-muted-foreground">
                        <LifeBuoy className="size-8" />
                        Belum ada tiket.
                    </div>
                ) : (
                    <table className={tableStyles.table}>
                        <thead className={tableStyles.head}>
                            <tr>
                                <th className={tableStyles.th}>Subjek</th>
                                {box === 'inbox' && (
                                    <th className={tableStyles.th}>Dari</th>
                                )}
                                <th className={tableStyles.th}>Status</th>
                                <th className={tableStyles.th}>Prioritas</th>
                                <th className={`${tableStyles.th} text-right`}>
                                    Terakhir
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {tickets.data.map((ticket) => (
                                <tr key={ticket.id} className={tableStyles.row}>
                                    <td className={tableStyles.td}>
                                        <Link
                                            href={show(ticket.id)}
                                            className="font-semibold text-primary hover:underline"
                                        >
                                            {ticket.subject}
                                        </Link>
                                    </td>
                                    {box === 'inbox' && (
                                        <td
                                            className={`${tableStyles.td} whitespace-nowrap`}
                                        >
                                            {ticket.from.username}
                                        </td>
                                    )}
                                    <td className={tableStyles.td}>
                                        <StatusBadge
                                            status={
                                                ticketStatus[ticket.status]
                                                    .badge
                                            }
                                            label={
                                                ticketStatus[ticket.status]
                                                    .label
                                            }
                                        />
                                    </td>
                                    <td className={`${tableStyles.td} text-sm`}>
                                        {ticketPriority[ticket.priority]}
                                    </td>
                                    <td
                                        className={`${tableStyles.td} text-right text-xs whitespace-nowrap text-muted-foreground`}
                                    >
                                        {ticket.last_message_at &&
                                            formatRelative(
                                                ticket.last_message_at,
                                            )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </Panel>

            <Pagination page={tickets} />

            <Dialog open={creating} onOpenChange={setCreating}>
                <DialogContent className="rounded-2xl sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>Buat tiket</DialogTitle>
                        <DialogDescription>
                            {recipient === 'reseller'
                                ? 'Tiket dikirim ke penyedia hosting kamu.'
                                : 'Tiket dikirim ke administrator server.'}
                        </DialogDescription>
                    </DialogHeader>
                    <Form
                        {...store.form()}
                        className="grid gap-4"
                        onSuccess={() => setCreating(false)}
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="ticket-subject">
                                        Subjek
                                    </Label>
                                    <Input
                                        id="ticket-subject"
                                        name="subject"
                                        required
                                        maxLength={150}
                                    />
                                    <InputError message={errors.subject} />
                                </div>
                                <div className="grid gap-1.5 sm:max-w-48">
                                    <Label htmlFor="ticket-priority">
                                        Prioritas
                                    </Label>
                                    <input
                                        type="hidden"
                                        name="priority"
                                        value={priority}
                                    />
                                    <Select
                                        value={priority}
                                        onValueChange={setPriority}
                                    >
                                        <SelectTrigger id="ticket-priority">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {priorities.map((value) => (
                                                <SelectItem
                                                    key={value}
                                                    value={value}
                                                >
                                                    {ticketPriority[value]}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="ticket-body">Pesan</Label>
                                    <textarea
                                        id="ticket-body"
                                        name="body"
                                        required
                                        maxLength={max_body}
                                        className={textareaClass}
                                    />
                                    <InputError message={errors.body} />
                                </div>
                                <div>
                                    <Button type="submit" disabled={processing}>
                                        {processing && <Spinner />}
                                        Kirim tiket
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>
        </>
    );
}
