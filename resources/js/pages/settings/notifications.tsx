import { Form, Head, router } from '@inertiajs/react';
import { Bell, Mail, Send, Trash2 } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import SettingsCard from '@/components/settings-card';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatRelative } from '@/lib/format';
import { destroy, test, update } from '@/routes/notifications';

type ChannelType = 'email' | 'telegram';

type Channel = {
    target: string;
    events: string[];
    enabled: boolean;
    last_sent_at: string | null;
    last_error: string | null;
};

type Props = {
    channels: Record<ChannelType, Channel | null>;
    events: string[];
    account_email: string;
    telegram_bot: string | null;
};

const eventLabels: Record<string, { title: string; description: string }> = {
    service_failed: {
        title: 'Layanan server mati',
        description:
            'Nginx, MariaDB, email, dan lainnya berhenti, termasuk saat watchdog berhasil menyalakannya lagi.',
    },
    disk_full: {
        title: 'Disk server hampir penuh',
        description: 'Disk server terpakai 90% atau lebih.',
    },
    quota_exceeded: {
        title: 'Kuota habis',
        description:
            'Disk atau bandwidth akun habis, atau website berhenti karena batasnya.',
    },
    ticket: {
        title: 'Tiket',
        description: 'Tiket baru atau balasan untuk tiket kamu.',
    },
    ssl_failed: {
        title: 'Masalah sertifikat SSL',
        description:
            'Sertifikat gagal dibuat, atau habis dalam 7 hari dan belum diperpanjang.',
    },
    backup: {
        title: 'Backup otomatis',
        description:
            'Backup otomatis dilewati karena ruang disk kurang, atau ada akun yang gagal di-backup.',
    },
};

export default function Notifications({
    channels,
    events,
    account_email,
    telegram_bot,
}: Props) {
    return (
        <>
            <Head title="Notifikasi" />

            <ChannelCard
                type="email"
                icon={Mail}
                title="Email"
                description="Notifikasi dikirim ke alamat email ini. Notifikasi yang sama dikirim paling banyak sekali sehari."
                channel={channels.email}
                events={events}
                defaultTarget={account_email}
            >
                {({ target, setTarget, invalid }) => (
                    <Input
                        id="email-target"
                        name="target"
                        type="email"
                        required
                        value={target}
                        onChange={(event) => setTarget(event.target.value)}
                        aria-invalid={invalid ? true : undefined}
                    />
                )}
            </ChannelCard>

            {telegram_bot === null ? (
                <SettingsCard
                    icon={Send}
                    title="Telegram"
                    description="Administrator server belum memasang bot Telegram, jadi notifikasi hanya bisa lewat email."
                >
                    <p className="text-sm text-muted-foreground">
                        Belum tersedia.
                    </p>
                </SettingsCard>
            ) : (
                <ChannelCard
                    type="telegram"
                    icon={Send}
                    title="Telegram"
                    description={`Notifikasi dikirim oleh @${telegram_bot}. Buka @${telegram_bot} di Telegram dan tekan Start dulu, supaya bot boleh mengirim pesan ke kamu. Chat ID kamu bisa dilihat dengan mengirim pesan ke @userinfobot.`}
                    channel={channels.telegram}
                    events={events}
                    defaultTarget=""
                >
                    {({ target, setTarget, invalid }) => (
                        <Input
                            id="telegram-target"
                            name="target"
                            inputMode="numeric"
                            required
                            placeholder="Chat ID, misalnya 123456789"
                            value={target}
                            onChange={(event) => setTarget(event.target.value)}
                            aria-invalid={invalid ? true : undefined}
                        />
                    )}
                </ChannelCard>
            )}
        </>
    );
}

function ChannelCard({
    type,
    icon,
    title,
    description,
    channel,
    events,
    defaultTarget,
    children,
}: {
    type: ChannelType;
    icon: typeof Mail;
    title: string;
    description: string;
    channel: Channel | null;
    events: string[];
    defaultTarget: string;
    children: (field: {
        target: string;
        setTarget: (value: string) => void;
        invalid: boolean;
    }) => React.ReactNode;
}) {
    const [target, setTarget] = useState(channel?.target ?? defaultTarget);
    const [enabled, setEnabled] = useState(channel?.enabled ?? true);
    const [chosen, setChosen] = useState<string[]>(channel?.events ?? events);
    const [testing, setTesting] = useState(false);

    const toggle = (event: string, checked: boolean) =>
        setChosen((current) =>
            checked
                ? [...current, event]
                : current.filter((value) => value !== event),
        );

    return (
        <SettingsCard icon={icon} title={title} description={description}>
            <Form
                {...update.form(type)}
                options={{ preserveScroll: true }}
                transform={(data) => ({
                    ...data,
                    target,
                    enabled,
                    events: chosen,
                })}
                className="grid gap-5"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-1.5 sm:max-w-sm">
                            <Label htmlFor={`${type}-target`}>
                                {type === 'email' ? 'Alamat email' : 'Chat ID'}
                            </Label>
                            {children({
                                target,
                                setTarget,
                                invalid: Boolean(errors.target),
                            })}
                            <InputError message={errors.target} />
                        </div>

                        <fieldset className="grid gap-3">
                            <legend className="mb-1 text-sm font-medium">
                                Kirim notifikasi untuk
                            </legend>
                            {events.map((event) => (
                                <div
                                    key={event}
                                    className="flex items-start gap-3"
                                >
                                    <Checkbox
                                        id={`${type}-${event}`}
                                        checked={chosen.includes(event)}
                                        onCheckedChange={(checked) =>
                                            toggle(event, checked === true)
                                        }
                                        className="mt-0.5"
                                    />
                                    <Label
                                        htmlFor={`${type}-${event}`}
                                        className="grid gap-0.5 font-normal"
                                    >
                                        <span>
                                            {eventLabels[event]?.title ?? event}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {eventLabels[event]?.description}
                                        </span>
                                    </Label>
                                </div>
                            ))}
                            <InputError message={errors.events} />
                        </fieldset>

                        <div className="flex items-center gap-3">
                            <Checkbox
                                id={`${type}-enabled`}
                                checked={enabled}
                                onCheckedChange={(checked) =>
                                    setEnabled(checked === true)
                                }
                            />
                            <Label htmlFor={`${type}-enabled`}>Aktif</Label>
                        </div>

                        <div className="flex flex-wrap items-center gap-2">
                            <Button type="submit" disabled={processing}>
                                {processing ? <Spinner /> : <Bell />}
                                Simpan
                            </Button>
                            {channel !== null && (
                                <>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        disabled={testing}
                                        onClick={() =>
                                            router.post(
                                                test.url(type),
                                                {},
                                                {
                                                    preserveScroll: true,
                                                    onStart: () =>
                                                        setTesting(true),
                                                    onFinish: () =>
                                                        setTesting(false),
                                                },
                                            )
                                        }
                                    >
                                        {testing ? <Spinner /> : <Send />}
                                        Kirim tes
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        onClick={() =>
                                            router.delete(destroy.url(type), {
                                                preserveScroll: true,
                                            })
                                        }
                                    >
                                        <Trash2 /> Hapus
                                    </Button>
                                </>
                            )}
                        </div>

                        {channel !== null &&
                            (channel.last_error !== null ||
                                channel.last_sent_at !== null) && (
                                <p
                                    className={
                                        channel.last_error !== null
                                            ? 'text-sm text-destructive-foreground'
                                            : 'text-xs text-muted-foreground'
                                    }
                                >
                                    {channel.last_error !== null
                                        ? `Notifikasi terakhir gagal dikirim: ${channel.last_error}`
                                        : `Notifikasi terakhir terkirim ${formatRelative(channel.last_sent_at!)}.`}
                                </p>
                            )}
                    </>
                )}
            </Form>
        </SettingsCard>
    );
}
