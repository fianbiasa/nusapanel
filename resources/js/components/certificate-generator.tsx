import { Form } from '@inertiajs/react';
import { Copy, FileKey, ShieldAlert, Trash2 } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    DialogClose,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useClipboard } from '@/hooks/use-clipboard';
import { formatDateTime } from '@/lib/format';
import {
    destroy as destroyRequest,
    store as storeRequest,
} from '@/routes/domains/certificate-request';
import { store as storeSelfSigned } from '@/routes/domains/self-signed-certificate';

/** A website as the certificate forms need it. */
export type CertificateDomain = {
    id: number;
    name: string;
    display_name: string;
    type: string;
    certificate_request: {
        names: string[];
        key_type: string;
        csr: string;
        created_at: string | null;
    } | null;
};

const keyTypes: { value: string; label: string }[] = [
    { value: 'rsa2048', label: 'RSA 2048 bit (paling umum)' },
    { value: 'rsa4096', label: 'RSA 4096 bit' },
    { value: 'ec256', label: 'ECDSA P-256 (lebih ringan)' },
];

const subjectFields: {
    name: string;
    label: string;
    placeholder: string;
    maxLength: number;
}[] = [
    {
        name: 'organization',
        label: 'Organisasi',
        placeholder: 'PT Contoh Jaya',
        maxLength: 64,
    },
    {
        name: 'organizational_unit',
        label: 'Unit',
        placeholder: 'IT',
        maxLength: 64,
    },
    { name: 'locality', label: 'Kota', placeholder: 'Jakarta', maxLength: 64 },
    {
        name: 'state',
        label: 'Provinsi',
        placeholder: 'DKI Jakarta',
        maxLength: 64,
    },
    {
        name: 'country',
        label: 'Negara (2 huruf)',
        placeholder: 'ID',
        maxLength: 2,
    },
    { name: 'email', label: 'Email', placeholder: '', maxLength: 64 },
];

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

const csrClass =
    'min-h-48 w-full rounded-xl border border-input bg-muted/40 px-3.5 py-2.5 font-mono text-xs shadow-xs outline-none';

/**
 * The names a new certificate covers, its key and its subject: the fields
 * the CSR and the self-signed certificate share.
 */
function CertificateFields({
    domain,
    errors,
}: {
    domain: CertificateDomain;
    errors: Record<string, string>;
}) {
    const [wildcard, setWildcard] = useState(false);

    return (
        <>
            <div className="grid gap-2 rounded-xl border px-4 py-3 text-sm">
                <span className="font-medium">Nama di sertifikat</span>
                <label className="flex items-center gap-2">
                    <input
                        type="checkbox"
                        name="www"
                        value="1"
                        defaultChecked
                        disabled={wildcard}
                        className="size-4 accent-primary"
                    />
                    {domain.display_name} dan www.{domain.display_name}
                </label>
                {domain.type === 'domain' && (
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            name="wildcard"
                            value="1"
                            checked={wildcard}
                            onChange={(event) =>
                                setWildcard(event.target.checked)
                            }
                            className="size-4 accent-primary"
                        />
                        Wildcard: semua subdomain (*.{domain.display_name})
                    </label>
                )}
            </div>

            <div className="grid gap-2">
                <Label htmlFor="key_type">Jenis key</Label>
                <select
                    id="key_type"
                    name="key_type"
                    defaultValue="rsa2048"
                    className={selectClass}
                >
                    {keyTypes.map((type) => (
                        <option key={type.value} value={type.value}>
                            {type.label}
                        </option>
                    ))}
                </select>
                <InputError message={errors.key_type} />
            </div>

            <div className="grid gap-3 sm:grid-cols-2">
                {subjectFields.map((field) => (
                    <div key={field.name} className="grid gap-1.5">
                        <Label htmlFor={field.name}>{field.label}</Label>
                        <Input
                            id={field.name}
                            name={field.name}
                            maxLength={field.maxLength}
                            placeholder={
                                field.name === 'email'
                                    ? `admin@${domain.name}`
                                    : field.placeholder
                            }
                            aria-invalid={errors[field.name] ? true : undefined}
                        />
                        <InputError message={errors[field.name]} />
                    </div>
                ))}
            </div>
            <p className="-mt-2 text-xs text-muted-foreground">
                Kolom di atas boleh kosong; sertifikat OV/EV dari certificate
                authority memintanya.
            </p>
        </>
    );
}

/**
 * The website's CSR to copy to a certificate authority, or the form that
 * makes one. Its private key stays on the server and is used when the
 * signed certificate is installed.
 */
export function CertificateRequestForm({
    domain,
    onClose,
}: {
    domain: CertificateDomain;
    onClose: () => void;
}) {
    const request = domain.certificate_request;
    const [making, setMaking] = useState(request === null);
    const [copied, copy] = useClipboard();

    if (request !== null && !making) {
        return (
            <div className="grid gap-5">
                <DialogHeader>
                    <DialogTitle>CSR {domain.display_name}</DialogTitle>
                    <DialogDescription>
                        Salin ke certificate authority (penjual SSL). Setelah
                        sertifikatnya terbit, pasang lewat “Pasang sertifikat
                        sendiri” dan kosongkan kolom private key: panel memakai
                        key CSR ini, yang tidak pernah ditampilkan.
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-2">
                    <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
                        <span className="font-mono">
                            {request.names.join(', ')}
                        </span>
                        {request.created_at && (
                            <span>
                                Dibuat {formatDateTime(request.created_at)}
                            </span>
                        )}
                    </div>
                    <textarea
                        readOnly
                        value={request.csr}
                        aria-label="CSR"
                        spellCheck={false}
                        className={csrClass}
                        onFocus={(event) => event.target.select()}
                    />
                </div>

                <DialogFooter className="flex-wrap gap-2 sm:justify-between">
                    <Form
                        {...destroyRequest.form(domain.id)}
                        options={{ preserveScroll: true }}
                        onSuccess={onClose}
                    >
                        {({ processing }) => (
                            <Button
                                type="submit"
                                variant="ghost"
                                disabled={processing}
                            >
                                {processing ? <Spinner /> : <Trash2 />}
                                Hapus CSR
                            </Button>
                        )}
                    </Form>
                    <div className="flex gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setMaking(true)}
                        >
                            Buat CSR baru
                        </Button>
                        <Button type="button" onClick={() => copy(request.csr)}>
                            <Copy />
                            {copied === request.csr ? 'Tersalin' : 'Salin CSR'}
                        </Button>
                    </div>
                </DialogFooter>
            </div>
        );
    }

    return (
        <Form
            {...storeRequest.form(domain.id)}
            options={{ preserveScroll: true }}
            onSuccess={() => setMaking(false)}
            className="grid gap-5"
        >
            {({ processing, errors }) => (
                <>
                    <DialogHeader>
                        <DialogTitle>
                            Buat CSR {domain.display_name}
                        </DialogTitle>
                        <DialogDescription>
                            Certificate Signing Request untuk membeli sertifikat
                            dari certificate authority.
                            {request !== null &&
                                ' CSR dan key yang lama diganti.'}
                        </DialogDescription>
                    </DialogHeader>

                    <CertificateFields domain={domain} errors={errors} />

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Batal
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={processing}>
                            {processing ? <Spinner /> : <FileKey />}
                            Buat CSR
                        </Button>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}

/** Makes a self-signed certificate for the website and installs it. */
export function SelfSignedCertificateForm({
    domain,
    onSuccess,
}: {
    domain: CertificateDomain;
    onSuccess: () => void;
}) {
    return (
        <Form
            {...storeSelfSigned.form(domain.id)}
            options={{ preserveScroll: true }}
            onSuccess={onSuccess}
            className="grid gap-5"
        >
            {({ processing, errors }) => (
                <>
                    <DialogHeader>
                        <DialogTitle>
                            Sertifikat self-signed {domain.display_name}
                        </DialogTitle>
                        <DialogDescription>
                            Langsung dipasang, tapi browser menampilkan
                            peringatan karena tidak ditandatangani certificate
                            authority. Cocok untuk uji coba atau layanan
                            internal; untuk website umum pakai Let’s Encrypt.
                        </DialogDescription>
                    </DialogHeader>

                    <CertificateFields domain={domain} errors={errors} />

                    <div className="grid gap-2">
                        <Label htmlFor="days">Berlaku (hari)</Label>
                        <Input
                            id="days"
                            name="days"
                            type="number"
                            min={1}
                            max={3650}
                            defaultValue={365}
                            required
                            aria-invalid={errors.days ? true : undefined}
                        />
                        <InputError
                            message={errors.days ?? errors.certificate}
                        />
                    </div>

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Batal
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={processing}>
                            {processing ? <Spinner /> : <ShieldAlert />}
                            Buat &amp; pasang
                        </Button>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}
