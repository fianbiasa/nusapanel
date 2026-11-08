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
import { index, store, update } from '@/routes/admin/packages';
import type { HostingPackage, QuotaKey } from '@/types';

/** How each limit is asked for in the form. */
const quotaFields: Record<QuotaKey, { label: string; unit?: string }> = {
    disk_mb: { label: 'Disk', unit: 'MB' },
    bandwidth_mb: { label: 'Bandwidth per bulan', unit: 'MB' },
    domains: { label: 'Domain' },
    subdomains: { label: 'Subdomain' },
    email_accounts: { label: 'Akun email' },
    databases: { label: 'Database' },
    ftp_accounts: { label: 'Akun FTP' },
    cron_jobs: { label: 'Cron job' },
};

/**
 * The form for creating a package, or changing `pkg` when given. Every
 * limit is always sent; an empty limit means unlimited.
 */
export function PackageForm({ pkg = null }: { pkg?: HostingPackage | null }) {
    const [ssl, setSsl] = useState(pkg?.ssl ?? true);

    return (
        <Form
            {...(pkg ? update.form(pkg.id) : store.form())}
            options={{ preserveScroll: true }}
            className="grid max-w-4xl gap-6"
        >
            {({ processing, errors }) => (
                <>
                    <Panel
                        title="Nama paket"
                        description="Nama ini yang dipilih saat membuat atau memindahkan akun."
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
                                    placeholder="mis. Basic"
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
                        description="Centang “Tanpa batas” untuk kuota yang tidak dibatasi. Saat ini baru batas domain yang diterapkan; batas lainnya disimpan dan mulai berlaku begitu fiturnya tersedia."
                    >
                        <div className="grid gap-5 md:grid-cols-2">
                            {quotaKeys.map((key) => (
                                <QuotaField
                                    key={key}
                                    name={key}
                                    label={quotaFields[key].label}
                                    unit={quotaFields[key].unit}
                                    defaultValue={pkg ? pkg.limits[key] : null}
                                    error={errors[key]}
                                />
                            ))}
                        </div>
                    </Panel>

                    <Panel title="Fitur">
                        <input type="hidden" name="ssl" value={ssl ? 1 : 0} />
                        <div className="flex items-start gap-3">
                            <Checkbox
                                id="ssl"
                                checked={ssl}
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
                                    Akun bisa memasang sertifikat SSL untuk
                                    domain-nya. Sertifikat yang sudah terpasang
                                    tidak dilepas kalau fitur ini dimatikan.
                                </p>
                            </div>
                        </div>
                        <InputError message={errors.ssl} className="mt-2" />
                    </Panel>

                    <div className="flex items-center gap-2">
                        <Button type="submit" size="lg" disabled={processing}>
                            {processing ? <Spinner /> : <Check />}
                            {pkg ? 'Simpan paket' : 'Buat paket'}
                        </Button>
                        <Button variant="ghost" size="lg" asChild>
                            <Link href={index()}>Batal</Link>
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

/**
 * A number input for one limit with a "Tanpa batas" checkbox. While
 * unlimited, an empty value is sent in its place.
 */
function QuotaField({
    name,
    label,
    unit,
    defaultValue,
    error,
}: {
    name: QuotaKey;
    label: string;
    unit?: string;
    defaultValue: number | null;
    error?: string;
}) {
    const [unlimited, setUnlimited] = useState(defaultValue === null);
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
                        min={0}
                        step={1}
                        required={!unlimited}
                        disabled={unlimited}
                        value={unlimited ? '' : value}
                        onChange={(event) => setValue(event.target.value)}
                        placeholder={unlimited ? 'Tanpa batas' : '0'}
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
            <InputError message={error} />
        </div>
    );
}
