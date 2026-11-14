import { Form, Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Send } from 'lucide-react';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Panel } from '@/components/panel';
import { StatusBadge } from '@/components/status-badge';
import {
    TicketAttachmentList,
    TicketAttachmentsInput,
} from '@/components/ticket-attachments';
import type {
    AttachmentLimits,
    TicketAttachment,
} from '@/components/ticket-attachments';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime } from '@/lib/format';
import { ticketPriority, ticketStatus } from '@/lib/tickets';
import { cn } from '@/lib/utils';
import { close, index, reopen, reply } from '@/routes/tickets';

type Ticket = {
    id: number;
    subject: string;
    status: string;
    priority: string;
    from: { username: string; name: string };
    answering: boolean;
    created_at: string | null;
};

type Message = {
    id: number;
    body: string;
    author: { username: string; name: string; role: string } | null;
    mine: boolean;
    created_at: string | null;
    attachments: TicketAttachment[];
};

const roleLabels: Record<string, string> = {
    admin: 'Administrator',
    reseller: 'Reseller',
    user: '',
};

const textareaClass =
    'min-h-32 w-full rounded-xl border border-input bg-card px-3.5 py-2.5 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive aria-invalid:ring-destructive/20';

export default function TicketShow({
    ticket,
    messages,
    max_body,
    attachment_limits,
}: {
    ticket: Ticket;
    messages: Message[];
    max_body: number;
    attachment_limits: AttachmentLimits;
}) {
    const closed = ticket.status === 'closed';

    return (
        <>
            <Head title={ticket.subject} />

            <div>
                <Button variant="ghost" size="sm" asChild className="-ml-2">
                    <Link
                        href={index({
                            query: ticket.answering
                                ? { box: 'inbox' }
                                : { box: 'mine' },
                        })}
                    >
                        <ArrowLeft /> Tiket
                    </Link>
                </Button>
            </div>

            <PageHeader
                title={ticket.subject}
                description={
                    <span className="flex flex-wrap items-center gap-2">
                        <StatusBadge
                            status={ticketStatus[ticket.status].badge}
                            label={ticketStatus[ticket.status].label}
                        />
                        <span>
                            Prioritas{' '}
                            {ticketPriority[ticket.priority].toLowerCase()}
                        </span>
                        {ticket.answering && (
                            <span>· dari {ticket.from.username}</span>
                        )}
                    </span>
                }
                actions={
                    <Button
                        variant="outline"
                        onClick={() =>
                            router.post(
                                (closed ? reopen : close).url(ticket.id),
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        {closed ? 'Buka lagi' : 'Tutup tiket'}
                    </Button>
                }
            />

            <div className="grid gap-3">
                {messages.map((message) => (
                    <article
                        key={message.id}
                        className={cn(
                            'max-w-3xl rounded-2xl border px-5 py-4 shadow-card',
                            message.mine ? 'ml-auto bg-primary/5' : 'bg-card',
                        )}
                    >
                        <header className="mb-2 flex flex-wrap items-baseline gap-x-2 text-xs text-muted-foreground">
                            <span className="font-semibold text-foreground">
                                {message.mine
                                    ? 'Kamu'
                                    : (message.author?.name ??
                                      'Akun yang sudah dihapus')}
                            </span>
                            {!message.mine &&
                                message.author &&
                                roleLabels[message.author.role] && (
                                    <span>
                                        {roleLabels[message.author.role]}
                                    </span>
                                )}
                            {message.created_at && (
                                <span>
                                    · {formatDateTime(message.created_at)}
                                </span>
                            )}
                        </header>
                        <p className="text-sm whitespace-pre-wrap">
                            {message.body}
                        </p>
                        <TicketAttachmentList
                            attachments={message.attachments}
                        />
                    </article>
                ))}
            </div>

            <Panel
                title={closed ? 'Tiket ini sudah ditutup' : 'Balas'}
                description={
                    closed ? 'Membalas akan membukanya lagi.' : undefined
                }
            >
                <Form
                    {...reply.form(ticket.id)}
                    options={{ preserveScroll: true }}
                    resetOnSuccess
                    className="grid gap-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <textarea
                                name="body"
                                required
                                maxLength={max_body}
                                aria-label="Balasan"
                                className={textareaClass}
                            />
                            <InputError message={errors.body} />
                            <TicketAttachmentsInput
                                id="reply-attachments"
                                limits={attachment_limits}
                                errors={errors}
                            />
                            <div>
                                <Button type="submit" disabled={processing}>
                                    {processing ? <Spinner /> : <Send />}
                                    Kirim
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </Panel>
        </>
    );
}
