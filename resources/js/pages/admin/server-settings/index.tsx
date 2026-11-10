import { Form, Head, router, usePoll } from '@inertiajs/react';
import { Clock, ShieldAlert, ShieldCheck, Upload } from 'lucide-react';
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
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime, formatRelative } from '@/lib/format';
import {
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

type Props = {
    server: {
        hostname: string;
        timezone: string;
        certificate: HostnameCertificate | null;
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

    // Follow a certificate request until it finishes.
    useEffect(() => {
        if (certificate_request.pending) {
            start();
        } else {
            stop();
        }
    }, [certificate_request.pending, start, stop]);

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
