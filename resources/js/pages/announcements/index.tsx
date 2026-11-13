import { Form, Head } from '@inertiajs/react';
import { Megaphone, Send } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Panel } from '@/components/panel';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime } from '@/lib/format';
import { store } from '@/routes/announcements';

type Announcement = {
    id: number;
    subject: string;
    body: string;
    from: string;
    created_at: string | null;
    audience?: string[];
    recipient_count?: number;
    emailed?: boolean;
};

type Props = {
    received: Announcement[];
    sent: Announcement[];
    can_send: boolean;
    audiences: Partial<Record<'reseller' | 'user', number>>;
    max_body: number;
};

const audienceLabels: Record<string, string> = {
    reseller: 'Semua reseller',
    user: 'Semua akun hosting',
};

const textareaClass =
    'min-h-40 w-full rounded-xl border border-input bg-card px-3.5 py-2.5 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive aria-invalid:ring-destructive/20';

export default function Announcements({
    received,
    sent,
    can_send,
    audiences,
    max_body,
}: Props) {
    const isAdmin = 'reseller' in audiences;
    const [chosen, setChosen] = useState<string[]>(['user']);
    const [email, setEmail] = useState(false);

    return (
        <>
            <Head title="Pesan massal" />

            <PageHeader
                title={can_send ? 'Pesan massal' : 'Pengumuman'}
                description={
                    can_send
                        ? isAdmin
                            ? 'Kirim pesan ke semua reseller dan/atau semua akun hosting. Pesan tampil di dashboard mereka, dan bisa juga dikirim lewat email.'
                            : 'Kirim pesan ke semua akun hosting kamu. Pesan tampil di dashboard mereka, dan bisa juga dikirim lewat email.'
                        : 'Pengumuman dari penyedia hosting kamu.'
                }
            />

            {can_send && (
                <Panel title="Kirim pesan">
                    <Form
                        {...store.form()}
                        options={{ preserveScroll: true }}
                        resetOnSuccess
                        transform={(data) => ({
                            ...data,
                            audience: chosen,
                            email,
                        })}
                        className="grid gap-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                {isAdmin ? (
                                    <fieldset className="grid gap-2">
                                        <legend className="mb-1 text-sm font-medium">
                                            Penerima
                                        </legend>
                                        {(['reseller', 'user'] as const).map(
                                            (role) => (
                                                <div
                                                    key={role}
                                                    className="flex items-center gap-3"
                                                >
                                                    <Checkbox
                                                        id={`audience-${role}`}
                                                        checked={chosen.includes(
                                                            role,
                                                        )}
                                                        onCheckedChange={(
                                                            checked,
                                                        ) =>
                                                            setChosen(
                                                                (current) =>
                                                                    checked ===
                                                                    true
                                                                        ? [
                                                                              ...current,
                                                                              role,
                                                                          ]
                                                                        : current.filter(
                                                                              (
                                                                                  value,
                                                                              ) =>
                                                                                  value !==
                                                                                  role,
                                                                          ),
                                                            )
                                                        }
                                                    />
                                                    <Label
                                                        htmlFor={`audience-${role}`}
                                                    >
                                                        {audienceLabels[role]} (
                                                        {audiences[role]})
                                                    </Label>
                                                </div>
                                            ),
                                        )}
                                        <InputError message={errors.audience} />
                                    </fieldset>
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        Penerima: {audiences.user} akun hosting
                                        kamu yang aktif.
                                    </p>
                                )}
                                <div className="grid gap-1.5">
                                    <Label htmlFor="announcement-subject">
                                        Subjek
                                    </Label>
                                    <Input
                                        id="announcement-subject"
                                        name="subject"
                                        required
                                        maxLength={150}
                                    />
                                    <InputError message={errors.subject} />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="announcement-body">
                                        Pesan
                                    </Label>
                                    <textarea
                                        id="announcement-body"
                                        name="body"
                                        required
                                        maxLength={max_body}
                                        className={textareaClass}
                                    />
                                    <InputError message={errors.body} />
                                </div>
                                <div className="flex items-center gap-3">
                                    <Checkbox
                                        id="announcement-email"
                                        checked={email}
                                        onCheckedChange={(checked) =>
                                            setEmail(checked === true)
                                        }
                                    />
                                    <Label htmlFor="announcement-email">
                                        Kirim juga ke email setiap akun
                                    </Label>
                                </div>
                                <div>
                                    <Button type="submit" disabled={processing}>
                                        {processing ? <Spinner /> : <Send />}
                                        Kirim pesan
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </Panel>
            )}

            {can_send && sent.length > 0 && (
                <Panel title="Terkirim">
                    <ul className="divide-y">
                        {sent.map((announcement) => (
                            <li
                                key={announcement.id}
                                className="grid gap-1 py-3"
                            >
                                <span className="font-semibold">
                                    {announcement.subject}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {announcement.created_at &&
                                        formatDateTime(
                                            announcement.created_at,
                                        )}{' '}
                                    ·{' '}
                                    {(announcement.audience ?? [])
                                        .map((role) => audienceLabels[role])
                                        .join(' dan ')}{' '}
                                    · {announcement.recipient_count} akun
                                    {announcement.emailed &&
                                        ' · juga lewat email'}
                                </span>
                            </li>
                        ))}
                    </ul>
                </Panel>
            )}

            {(!can_send || received.length > 0) && (
                <Panel title="Diterima">
                    {received.length === 0 ? (
                        <p className="flex items-center gap-2 text-sm text-muted-foreground">
                            <Megaphone className="size-4" /> Belum ada
                            pengumuman.
                        </p>
                    ) : (
                        <div className="grid gap-4">
                            {received.map((announcement) => (
                                <article
                                    key={announcement.id}
                                    className="rounded-xl border px-5 py-4"
                                >
                                    <h3 className="font-semibold">
                                        {announcement.subject}
                                    </h3>
                                    <p className="mb-2 text-xs text-muted-foreground">
                                        {announcement.from}
                                        {announcement.created_at &&
                                            ` · ${formatDateTime(announcement.created_at)}`}
                                    </p>
                                    <p className="text-sm whitespace-pre-wrap">
                                        {announcement.body}
                                    </p>
                                </article>
                            ))}
                        </div>
                    )}
                </Panel>
            )}
        </>
    );
}
