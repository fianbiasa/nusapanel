import { Form, Head, router, usePoll } from '@inertiajs/react';
import {
    CheckCircle2,
    Clock,
    PencilLine,
    ShieldAlert,
    ShieldCheck,
    Upload,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useState } from 'react';
import { PageHeader } from '@/components/page-header';
import { Panel } from '@/components/panel';
import {
    Field,
    indonesianTimezones,
    SizeField,
} from '@/components/php-settings-form';
import { StatusBadge } from '@/components/status-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime, formatRelative } from '@/lib/format';
import {
    hostname as updateHostname,
    hostnameCertificate,
    phpUploads,
    timezone as updateTimezone,
} from '@/routes/admin/server-settings';

type HostnameCertificate = {
    hostname: string;
    names: string[];
    issuer: string;
    expires_at: string;
    lets_encrypt: boolean;
};

type CertificateRequest = { pending: boolean; error: string | null };

/** The latest hostname change: setup.sh running again in the background. */
type Reconfigure = {
    hostname: string;
    started_at: string;
    running: boolean;
    exit_code: number | null;
    error: string;
    log: string[];
} | null;

type Props = {
    server: {
        hostname: string;
        timezone: string;
        certificate: HostnameCertificate | null;
        reconfigure: Reconfigure;
    } | null;
    error: string | null;
    certificate_request: CertificateRequest;
    timezones: string[];
    php_uploads: { limit: string; default: string; sizes: string[] };
};

export default function ServerSettings({
    server,
    error,
    certificate_request,
    timezones,
    php_uploads,
}: Props) {
    const { start, stop } = usePoll(
        5_000,
        { only: ['server', 'certificate_request'] },
        { autoStart: false },
    );

    const busy =
        certificate_request.pending || server?.reconfigure?.running === true;

    // Follow a certificate request or a hostname change until it finishes.
    useEffect(() => {
        if (busy) {
            start();
        } else {
            stop();
        }
    }, [busy, start, stop]);

    return (
        <>
            <Head title="Pengaturan Server" />

            <PageHeader
                title="Pengaturan Server"
                description="Hostname dan SSL panel, zona waktu server, dan ukuran upload yang boleh dipakai website."
            />

            {error !== null && (
                <Alert variant="destructive">
                    <ShieldAlert />
                    <AlertDescription>{error}</AlertDescription>
                </Alert>
            )}

            {server !== null && (
                <HostnamePanel
                    hostname={server.hostname}
                    certificate={server.certificate}
                    request={certificate_request}
                />
            )}

            {server !== null && (
                <HostnameChangePanel
                    current={server.hostname}
                    change={server.reconfigure}
                />
            )}

            <div className="grid gap-6 lg:grid-cols-2">
                {server !== null && (
                    <TimezonePanel
                        current={server.timezone}
                        timezones={timezones}
                    />
                )}
                <UploadPanel uploads={php_uploads} />
            </div>
        </>
    );
}

function HostnamePanel({
    hostname,
    certificate,
    request,
}: {
    hostname: string;
    certificate: HostnameCertificate | null;
    request: CertificateRequest;
}) {
    const [confirming, setConfirming] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [actionError, setActionError] = useState<string | null>(null);

    const issue = () => {
        setProcessing(true);
        setActionError(null);
        router.post(
            hostnameCertificate().url,
            {},
            {
                preserveScroll: true,
                onSuccess: () => setConfirming(false),
                onError: (errors) =>
                    setActionError(Object.values(errors)[0] ?? null),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Panel
            title="Hostname & SSL"
            description="Sertifikat ini dipakai panel, phpMyAdmin dan FTP. Let's Encrypt memperpanjangnya otomatis."
            action={
                <Button
                    variant="secondary"
                    disabled={request.pending}
                    onClick={() => setConfirming(true)}
                >
                    {request.pending ? <Spinner /> : <ShieldCheck />}
                    {request.pending
                        ? 'Sedang meminta sertifikat…'
                        : 'Terbitkan ulang SSL'}
                </Button>
            }
        >
            <dl className="grid gap-x-8 gap-y-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <Detail label="Hostname">
                    <span className="font-mono">{hostname || '—'}</span>
                </Detail>
                <Detail label="Sertifikat">
                    {certificate === null ? (
                        <StatusBadge status="failed" label="Tidak terbaca" />
                    ) : certificate.lets_encrypt ? (
                        <StatusBadge status="active" label="Let's Encrypt" />
                    ) : (
                        <StatusBadge status="suspended" label="Self-signed" />
                    )}
                </Detail>
                <Detail label="Penerbit">{certificate?.issuer || '—'}</Detail>
                <Detail label="Berlaku sampai">
                    {certificate
                        ? `${formatDateTime(certificate.expires_at)} (${formatRelative(certificate.expires_at)})`
                        : '—'}
                </Detail>
            </dl>

            {certificate !== null && !certificate.lets_encrypt && (
                <Alert className="mt-5">
                    <ShieldAlert />
                    <AlertDescription>
                        Panel masih memakai sertifikat self-signed, jadi browser
                        menampilkan peringatan. Pastikan A record {hostname}{' '}
                        mengarah ke server ini dan port 80 terbuka, lalu
                        terbitkan ulang SSL.
                    </AlertDescription>
                </Alert>
            )}

            {request.error !== null && (
                <Alert variant="destructive" className="mt-5">
                    <ShieldAlert />
                    <AlertDescription className="break-words">
                        Permintaan terakhir gagal: {request.error}
                    </AlertDescription>
                </Alert>
            )}

            <Dialog
                open={confirming}
                onOpenChange={(open) => {
                    setConfirming(open);
                    setActionError(null);
                }}
            >
                <DialogContent className="rounded-2xl">
                    <DialogHeader>
                        <DialogTitle>
                            Terbitkan ulang SSL {hostname}?
                        </DialogTitle>
                        <DialogDescription>
                            Let's Encrypt memeriksa dulu bahwa {hostname} sampai
                            ke server ini lewat port 80. Sertifikat yang masih
                            berlaku lebih dari 30 hari dipakai lagi; panel dan
                            FTP lalu memakai sertifikat Let's Encrypt itu.
                        </DialogDescription>
                    </DialogHeader>
                    {actionError && (
                        <Alert variant="destructive">
                            <AlertDescription>{actionError}</AlertDescription>
                        </Alert>
                    )}
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Batal
                            </Button>
                        </DialogClose>
                        <Button disabled={processing} onClick={issue}>
                            {processing && <Spinner />}
                            Terbitkan ulang
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </Panel>
    );
}

function HostnameChangePanel({
    current,
    change,
}: {
    current: string;
    change: Reconfigure;
}) {
    const [open, setOpen] = useState(false);
    const port = typeof window === 'undefined' ? '' : window.location.port;
    const panelUrl = (name: string) =>
        `https://${name}${port ? `:${port}` : ''}`;

    return (
        <Panel
            title="Ganti hostname"
            description="Semua yang memakai hostname ikut diganti: SSL panel, phpMyAdmin, FTP, email panel (Postfix & DKIM) dan alamat panel."
            action={
                <Button
                    variant="secondary"
                    disabled={change?.running === true}
                    onClick={() => setOpen(true)}
                >
                    <PencilLine />
                    Ganti hostname
                </Button>
            }
        >
            {change === null ? (
                <p className="text-sm text-muted-foreground">
                    Syaratnya sama seperti saat instalasi: A record hostname
                    baru mengarah ke IP server ini, dan PTR (reverse DNS) IP
                    server mengarah ke hostname baru, diatur lewat provider VPS.
                    Kalau belum benar, tidak ada yang diubah.
                </p>
            ) : change.running ? (
                <Alert>
                    <Spinner />
                    <AlertDescription>
                        Mengganti hostname ke{' '}
                        <span className="font-mono">{change.hostname}</span>…
                        Panel sempat tidak menjawab sebentar saat layanannya
                        dijalankan ulang; halaman ini diperbarui sendiri.
                    </AlertDescription>
                </Alert>
            ) : change.exit_code === 0 ? (
                <Alert>
                    <CheckCircle2 />
                    <AlertDescription>
                        Hostname sekarang{' '}
                        <span className="font-mono">{change.hostname}</span>.
                        Buka panel di{' '}
                        <a
                            className="font-medium underline"
                            href={panelUrl(change.hostname)}
                        >
                            {panelUrl(change.hostname)}
                        </a>
                        , lalu perbarui rekaman SPF, DKIM dan DMARC di DNS
                        sesuai /etc/rayxo/dns-records.txt.
                    </AlertDescription>
                </Alert>
            ) : (
                <Alert variant="destructive">
                    <ShieldAlert />
                    <AlertDescription className="break-words">
                        Hostname tidak diganti ke{' '}
                        <span className="font-mono">{change.hostname}</span>:{' '}
                        {change.error ||
                            'proses berhenti sebelum selesai; lihat log di bawah.'}
                    </AlertDescription>
                </Alert>
            )}

            {change !== null && change.log.length > 0 && (
                <details className="mt-4 text-sm" open={change.running}>
                    <summary className="cursor-pointer text-muted-foreground">
                        Log proses
                    </summary>
                    <pre className="mt-2 max-h-72 overflow-auto rounded-xl bg-muted p-3 text-xs leading-relaxed whitespace-pre-wrap">
                        {change.log.join('\n')}
                    </pre>
                </details>
            )}

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="rounded-2xl">
                    <Form
                        {...updateHostname.form()}
                        options={{ preserveScroll: true }}
                        onSuccess={() => setOpen(false)}
                        className="grid gap-5"
                    >
                        {({ processing, errors }) => (
                            <>
                                <DialogHeader>
                                    <DialogTitle>
                                        Ganti hostname server
                                    </DialogTitle>
                                    <DialogDescription>
                                        Sekarang{' '}
                                        <span className="font-mono">
                                            {current}
                                        </span>
                                        . Sebelum lanjut, arahkan A record
                                        hostname baru ke server ini dan minta
                                        provider VPS mengatur PTR ke hostname
                                        baru. Prosesnya beberapa menit; setelah
                                        selesai panel dibuka lewat hostname
                                        baru.
                                    </DialogDescription>
                                </DialogHeader>
                                <Field
                                    id="hostname"
                                    label="Hostname baru"
                                    error={errors.hostname}
                                >
                                    <Input
                                        id="hostname"
                                        name="hostname"
                                        placeholder="server2.domainmu.com"
                                        autoComplete="off"
                                        className="rounded-xl font-mono"
                                        required
                                    />
                                </Field>
                                <DialogFooter className="gap-2">
                                    <DialogClose asChild>
                                        <Button
                                            type="button"
                                            variant="secondary"
                                        >
                                            Batal
                                        </Button>
                                    </DialogClose>
                                    <Button type="submit" disabled={processing}>
                                        {processing && <Spinner />}
                                        Ganti hostname
                                    </Button>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>
        </Panel>
    );
}

function TimezonePanel({
    current,
    timezones,
}: {
    current: string;
    timezones: string[];
}) {
    const [value, setValue] = useState(current || 'UTC');
    const ordered = [
        ...indonesianTimezones,
        ...timezones.filter((zone) => !indonesianTimezones.includes(zone)),
    ];

    return (
        <Panel
            title="Zona waktu"
            description="Dipakai jadwal cron job dan log server. Website punya zona waktunya sendiri di pengaturan PHP."
        >
            <Form
                {...updateTimezone.form()}
                options={{ preserveScroll: true }}
                className="grid gap-4"
            >
                {({ processing, errors }) => (
                    <>
                        <Field
                            id="timezone"
                            label="Zona waktu server"
                            error={errors.timezone}
                        >
                            <input
                                type="hidden"
                                name="timezone"
                                value={value}
                            />
                            <Select value={value} onValueChange={setValue}>
                                <SelectTrigger
                                    id="timezone"
                                    aria-invalid={
                                        errors.timezone ? true : undefined
                                    }
                                    className="w-full rounded-xl bg-card px-3.5 data-[size=default]:h-11"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent className="max-h-80 rounded-xl">
                                    {ordered.map((zone) => (
                                        <SelectItem key={zone} value={zone}>
                                            {zone}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <div>
                            <Button
                                type="submit"
                                disabled={processing || value === current}
                            >
                                {processing ? <Spinner /> : <Clock />}
                                Simpan zona waktu
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </Panel>
    );
}

function UploadPanel({
    uploads,
}: {
    uploads: { limit: string; default: string; sizes: string[] };
}) {
    const [limit, setLimit] = useState(uploads.limit);
    const [fallback, setFallback] = useState(uploads.default);

    return (
        <Panel
            title="Batas upload PHP"
            description="Website yang belum memilih ukurannya sendiri memakai ukuran bawaan. Menyimpan perubahan menerapkan ulang semua website."
        >
            <Form
                {...phpUploads.form()}
                options={{ preserveScroll: true }}
                className="grid gap-4"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <SizeField
                                id="limit"
                                label="Maksimum yang boleh dipilih"
                                value={limit}
                                sizes={uploads.sizes}
                                onChange={setLimit}
                                error={errors.limit}
                            />
                            <SizeField
                                id="default"
                                label="Ukuran bawaan"
                                value={fallback}
                                sizes={uploads.sizes}
                                onChange={setFallback}
                                error={errors.default}
                            />
                        </div>
                        <div>
                            <Button
                                type="submit"
                                disabled={
                                    processing ||
                                    (limit === uploads.limit &&
                                        fallback === uploads.default)
                                }
                            >
                                {processing ? <Spinner /> : <Upload />}
                                Simpan batas upload
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </Panel>
    );
}

function Detail({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid gap-1">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="font-medium">{children}</dd>
        </div>
    );
}
