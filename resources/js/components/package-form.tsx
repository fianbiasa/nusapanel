import { Form, Link } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Panel } from '@/components/panel';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatMegabytes, quotaKeys } from '@/lib/format';
import type {
    QuotaKey,
    ResellerPackageLimits,
    ResellerQuotaKey,
} from '@/types';
import type { RouteDefinition, RouteFormDefinition } from '@/wayfinder';

/**
 * How each limit is asked for in the form. Disk space and inodes start at 1:
 * the server reads a disk quota of 0 as unlimited; a reseller gets at
 * least one account.
 */
const quotaFields: Record<
    ResellerQuotaKey,
    { label: string; unit?: string; min?: number; hint?: string }
> = {
    accounts: {
        label: 'Jumlah akun',
        min: 1,
        hint: 'Berapa akun hosting yang boleh dibuat reseller.',
    },
    disk_mb: { label: 'Disk', unit: 'MB', min: 1 },
    inodes: {
        label: 'Inode (jumlah file)',
        min: 1,
        hint: 'Jumlah file dan folder yang boleh dimiliki akun.',
    },
    bandwidth_mb: { label: 'Bandwidth per bulan', unit: 'MB' },
    domains: { label: 'Domain' },
    subdomains: { label: 'Subdomain' },
    pointers: {
        label: 'Pointer domain',
        hint: 'Domain lain yang menampilkan atau mengalihkan ke domain akun.',
    },
    email_accounts: { label: 'Akun email' },
    email_forwarders: { label: 'Forwarder email' },
    databases: { label: 'Database' },
    ftp_accounts: { label: 'Akun FTP' },
    cron_jobs: { label: 'Cron job' },
};

/** What the form starts from when it changes a package. */
export type PackageFormValues = {
    name: string;
    description: string | null;
    limits: Partial<Record<ResellerQuotaKey, number | null>>;
    ssl: boolean;
    cgi?: boolean;
    oversell?: boolean;
};

/**
 * The form for creating or changing (`pkg`) a hosting package, or with
 * `kind` "reseller" a reseller package, which also limits the number of
 * accounts and may oversell. Every limit is always sent; an empty limit
 * means unlimited. A reseller's own packages may not go over its reseller
 * package (`resellerLimits`): those limits cannot be left unlimited.
 */
export function PackageForm({
    pkg = null,
    form,
    cancelHref,
    kind = 'hosting',
    resellerLimits = null,
}: {
    pkg?: PackageFormValues | null;
    form: RouteFormDefinition<'post'> | RouteFormDefinition<'put'>;
    cancelHref: RouteDefinition<'get'>;
    kind?: 'hosting' | 'reseller';
    resellerLimits?: ResellerPackageLimits | null;
}) {
    const [ssl, setSsl] = useState(
        pkg?.ssl ?? (resellerLimits === null || resellerLimits.ssl === 1),
    );
    const [cgi, setCgi] = useState(pkg?.cgi ?? false);
    const [oversell, setOversell] = useState(pkg?.oversell ?? false);
    const keys: ResellerQuotaKey[] =
        kind === 'reseller' ? ['accounts', ...quotaKeys] : quotaKeys;
    const sslAllowed = resellerLimits === null || resellerLimits.ssl === 1;
    const cgiAllowed = resellerLimits === null || resellerLimits.cgi === 1;

    return (
        <Form
            {...form}
            options={{ preserveScroll: true }}
            className="grid max-w-4xl gap-6"
        >
            {({ processing, errors }) => (
                <>
                    <Panel
                        title="Nama paket"
                        description={
                            kind === 'reseller'
                                ? 'Nama ini yang dipilih saat membuat reseller atau mengganti paketnya.'
                                : 'Nama ini yang dipilih saat membuat atau memindahkan akun.'
                        }
                    >
                        <div className="grid gap-5 md:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="name">Nama</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    required
                                    autoFocus={pkg === null}
                                    autoComplete="off"
                                    maxLength={64}
                                    defaultValue={pkg?.name}
                                    placeholder={
                                        kind === 'reseller'
                                            ? 'mis. Reseller 10 akun'
                                            : 'mis. Basic'
                                    }
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="description">
                                    Deskripsi (opsional)
                                </Label>
                                <Input
                                    id="description"
                                    name="description"
                                    autoComplete="off"
                                    maxLength={255}
                                    defaultValue={pkg?.description ?? ''}
                                    placeholder="mis. Untuk website pribadi"
                                />
                                <InputError message={errors.description} />
                            </div>
                        </div>
                    </Panel>

                    <Panel
                        title="Kuota"
                        description={
                            kind === 'reseller'
                                ? 'Jatah yang boleh dibagikan reseller ke semua akunnya, dijumlah. Centang “Tanpa batas” untuk kuota yang tidak dibatasi.'
                                : resellerLimits !== null
                                  ? 'Setiap kuota paling banyak sebesar jatah paket reseller Anda. Kuota yang dibatasi jatah Anda tidak bisa dibuat tanpa batas.'
                                  : 'Centang “Tanpa batas” untuk kuota yang tidak dibatasi. Batas domain, SSL, disk, dan inode langsung diterapkan (disk dan inode oleh kuota disk server, bila sudah aktif). Batas email, database, FTP, cron job, subdomain, dan bandwidth disimpan dan mulai berlaku begitu fiturnya tersedia.'
                        }
                    >
                        <div className="grid gap-5 md:grid-cols-2">
                            {keys.map((key) => {
                                const cap =
                                    key !== 'accounts' && resellerLimits
                                        ? resellerLimits[key as QuotaKey]
                                        : null;

                                return (
                                    <QuotaField
                                        key={key}
                                        name={key}
                                        label={quotaFields[key].label}
                                        unit={quotaFields[key].unit}
                                        min={quotaFields[key].min}
                                        max={cap}
                                        hint={
                                            cap !== null
                                                ? `Paling banyak ${quotaFields[key].unit === 'MB' ? formatMegabytes(cap) : cap.toLocaleString('id-ID')}, jatah paket reseller Anda.`
                                                : quotaFields[key].hint
                                        }
                                        defaultValue={
                                            pkg
                                                ? (pkg.limits[key] ?? null)
                                                : cap
                                        }
                                        error={errors[key]}
                                    />
                                );
                            })}
                        </div>
                    </Panel>

                    <Panel title="Fitur">
                        <div className="grid gap-5">
                            <div>
                                <input
                                    type="hidden"
                                    name="ssl"
                                    value={ssl ? 1 : 0}
                                />
                                <div className="flex items-start gap-3">
                                    <Checkbox
                                        id="ssl"
                                        checked={ssl}
                                        disabled={!sslAllowed}
                                        onCheckedChange={(checked) =>
                                            setSsl(checked === true)
                                        }
                                        className="mt-0.5"
                                    />
                                    <div className="grid gap-1">
                                        <Label htmlFor="ssl">
                                            SSL gratis (Let&apos;s Encrypt)
                                        </Label>
                                        <p className="text-xs text-muted-foreground">
                                            {!sslAllowed
                                                ? 'Paket reseller Anda tidak termasuk SSL.'
                                                : kind === 'reseller'
                                                  ? 'Akun-akun reseller ini bisa memasang sertifikat SSL untuk domain-nya.'
                                                  : 'Akun bisa memasang sertifikat SSL untuk domain-nya. Sertifikat yang sudah terpasang tidak dilepas kalau fitur ini dimatikan.'}
                                        </p>
                                    </div>
                                </div>
                                <InputError
                                    message={errors.ssl}
                                    className="mt-2"
                                />
                            </div>

                            <div>
                                <input
                                    type="hidden"
                                    name="cgi"
                                    value={cgi ? 1 : 0}
                                />
                                <div className="flex items-start gap-3">
                                    <Checkbox
                                        id="cgi"
                                        checked={cgi}
                                        disabled={!cgiAllowed && !cgi}
                                        onCheckedChange={(checked) =>
                                            setCgi(checked === true)
                                        }
                                        className="mt-0.5"
                                    />
                                    <div className="grid gap-1">
                                        <Label htmlFor="cgi">Skrip CGI</Label>
                                        <p className="text-xs text-muted-foreground">
                                            {!cgiAllowed
                                                ? 'Paket reseller Anda tidak termasuk CGI.'
                                                : 'Skrip di folder cgi-bin website bisa dijalankan. Skrip CGI bisa menjalankan program apa pun sebagai akun itu, setara akses shell. Mematikannya menghentikan CGI di semua website akun ini.'}
                                        </p>
                                    </div>
                                </div>
                                <InputError
                                    message={errors.cgi}
                                    className="mt-2"
                                />
                            </div>

                            {kind === 'reseller' && (
                                <div>
                                    <input
                                        type="hidden"
                                        name="oversell"
                                        value={oversell ? 1 : 0}
                                    />
                                    <div className="flex items-start gap-3">
                                        <Checkbox
                                            id="oversell"
                                            checked={oversell}
                                            onCheckedChange={(checked) =>
                                                setOversell(checked === true)
                                            }
                                            className="mt-0.5"
                                        />
                                        <div className="grid gap-1">
                                            <Label htmlFor="oversell">
                                                Overselling
                                            </Label>
                                            <p className="text-xs text-muted-foreground">
                                                Paket akun-akun reseller boleh
                                                dijumlah melebihi jatah di atas.
                                                Jumlah akun tetap dibatasi.
                                                Tanpa overselling, setiap akun
                                                reseller wajib punya paket yang
                                                membatasi kuota yang dibatasi di
                                                sini.
                                            </p>
                                        </div>
                                    </div>
                                    <InputError
                                        message={errors.oversell}
                                        className="mt-2"
                                    />
                                </div>
                            )}
                        </div>
                    </Panel>

                    <div className="flex items-center gap-2">
                        <Button type="submit" size="lg" disabled={processing}>
                            {processing ? <Spinner /> : <Check />}
                            {pkg ? 'Simpan paket' : 'Buat paket'}
                        </Button>
                        <Button variant="ghost" size="lg" asChild>
                            <Link href={cancelHref}>Batal</Link>
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

/**
 * A number input for one limit with a "Tanpa batas" checkbox. While
 * unlimited, an empty value is sent in its place. A limit with a `max`
 * cannot be unlimited.
 */
function QuotaField({
    name,
    label,
    unit,
    min = 0,
    max = null,
    hint,
    defaultValue,
    error,
}: {
    name: ResellerQuotaKey;
    label: string;
    unit?: string;
    min?: number;
    max?: number | null;
    hint?: string;
    defaultValue: number | null;
    error?: string;
}) {
    const [unlimited, setUnlimited] = useState(
        defaultValue === null && max === null,
    );
    const [value, setValue] = useState(
        defaultValue === null ? '' : String(defaultValue),
    );
    const amount = Number(value);
    const showsSize =
        unit === 'MB' && !unlimited && value !== '' && amount >= 1024;

    return (
        <div className="grid content-start gap-2">
            <Label htmlFor={name}>{label}</Label>
            <div className="flex items-center gap-4">
                <div className="relative flex-1">
                    <Input
                        id={name}
                        name={unlimited ? undefined : name}
                        type="number"
                        inputMode="numeric"
                        min={min}
                        max={max ?? undefined}
                        step={1}
                        required={!unlimited}
                        disabled={unlimited}
                        value={unlimited ? '' : value}
                        onChange={(event) => setValue(event.target.value)}
                        placeholder={unlimited ? 'Tanpa batas' : String(min)}
                        aria-invalid={error ? true : undefined}
                        className={unit ? 'pr-12' : undefined}
                    />
                    {unit && (
                        <span className="pointer-events-none absolute top-1/2 right-3.5 -translate-y-1/2 text-sm text-muted-foreground">
                            {unit}
                        </span>
                    )}
                </div>
                {unlimited && <input type="hidden" name={name} value="" />}
                <div className="flex shrink-0 items-center gap-2">
                    <Checkbox
                        id={`${name}_unlimited`}
                        checked={unlimited}
                        disabled={max !== null}
                        onCheckedChange={(checked) =>
                            setUnlimited(checked === true)
                        }
                    />
                    <Label
                        htmlFor={`${name}_unlimited`}
                        className="font-normal"
                    >
                        Tanpa batas
                    </Label>
                </div>
            </div>
            {unit === 'MB' && !unlimited && (
                <p className="text-xs text-muted-foreground">
                    {showsSize
                        ? `Sama dengan ${formatMegabytes(amount)}.`
                        : '1 GB = 1.024 MB.'}
                </p>
            )}
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
            <InputError message={error} />
        </div>
    );
}
