import { Form, Head } from '@inertiajs/react';
import { Check, Trash2 } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Panel } from '@/components/panel';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { StatusBadge } from '@/components/status-badge';
import { Spinner } from '@/components/ui/spinner';
import type { Branding } from '@/lib/branding';
import { update } from '@/routes/reseller/branding';
import { update as updateLoginDomain } from '@/routes/reseller/branding/login-domain';
import { update as updateNameservers } from '@/routes/reseller/branding/nameservers';

const defaultColor = '#086cf0';

/**
 * A reseller's own look of the panel for itself and its accounts, and the
 * nameservers of its accounts' zones.
 */
export default function ResellerBranding({
    branding,
    nameservers,
    panelNameservers,
    serverIp,
    loginDomain,
}: {
    branding: Branding;
    nameservers: string[];
    panelNameservers: string[];
    serverIp: string | null;
    /** The domain the panel answers at for the reseller, and how far it got. */
    loginDomain: {
        domain: string;
        status: 'pending' | 'active' | 'failed';
        error: string | null;
    } | null;
}) {
    const [color, setColor] = useState(branding.color ?? '');
    const [removeLogo, setRemoveLogo] = useState(false);

    return (
        <>
            <Head title="Branding" />

            <PageHeader
                title="Branding"
                description="Nama, warna, dan logo Anda menggantikan RAYXO di panel untuk Anda dan semua akun Anda."
            />

            <Panel
                title="Tampilan"
                description="Kosongkan nama dan warna untuk memakai tampilan RAYXO."
            >
                <Form
                    {...update.form()}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setRemoveLogo(false)}
                    className="grid max-w-2xl gap-5"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="brand_name">Nama</Label>
                                <Input
                                    id="brand_name"
                                    name="brand_name"
                                    maxLength={40}
                                    autoComplete="off"
                                    defaultValue={branding.name ?? ''}
                                    placeholder="mis. Webku Hosting"
                                />
                                <InputError message={errors.brand_name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="brand_color">Warna</Label>
                                <div className="flex items-center gap-3">
                                    <input
                                        type="color"
                                        aria-label="Pilih warna"
                                        value={color || defaultColor}
                                        onChange={(event) =>
                                            setColor(event.target.value)
                                        }
                                        className="size-10 cursor-pointer rounded-lg border bg-card p-1"
                                    />
                                    <Input
                                        id="brand_color"
                                        name="brand_color"
                                        value={color}
                                        onChange={(event) =>
                                            setColor(event.target.value)
                                        }
                                        maxLength={7}
                                        placeholder={defaultColor}
                                        className="max-w-36 font-mono"
                                    />
                                    {color !== '' && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => setColor('')}
                                        >
                                            Warna RAYXO
                                        </Button>
                                    )}
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    Dipakai untuk tombol, tautan, dan menu yang
                                    aktif.
                                </p>
                                <InputError message={errors.brand_color} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="logo">Logo</Label>
                                {branding.logo_url && (
                                    <div className="flex items-center gap-4">
                                        <img
                                            src={branding.logo_url}
                                            alt="Logo sekarang"
                                            className="size-14 rounded-xl border bg-card object-contain p-1"
                                        />
                                        <div className="flex items-center gap-2">
                                            <Checkbox
                                                id="remove_logo"
                                                checked={removeLogo}
                                                onCheckedChange={(checked) =>
                                                    setRemoveLogo(
                                                        checked === true,
                                                    )
                                                }
                                            />
                                            <Label
                                                htmlFor="remove_logo"
                                                className="font-normal"
                                            >
                                                Hapus logo
                                            </Label>
                                            <input
                                                type="hidden"
                                                name="remove_logo"
                                                value={removeLogo ? 1 : 0}
                                            />
                                        </div>
                                    </div>
                                )}
                                <Input
                                    id="logo"
                                    name="logo"
                                    type="file"
                                    accept="image/png,image/jpeg,image/webp"
                                />
                                <p className="text-xs text-muted-foreground">
                                    PNG, JPEG, atau WebP, paling besar 512 KB
                                    dan 2000 × 2000 piksel. Logo persegi tampil
                                    paling rapi.
                                </p>
                                <InputError message={errors.logo} />
                            </div>

                            <div>
                                <Button type="submit" disabled={processing}>
                                    {processing ? <Spinner /> : <Check />}
                                    Simpan tampilan
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </Panel>

            <Panel
                title="Domain login"
                description="Anda dan pelanggan Anda bisa masuk ke panel lewat domain Anda sendiri, misalnya panel.domainanda.com, dengan sertifikat Let's Encrypt."
            >
                <div className="grid max-w-2xl gap-5">
                    {loginDomain && (
                        <div className="grid gap-2 rounded-xl border p-4">
                            <div className="flex flex-wrap items-center gap-3">
                                <a
                                    href={`https://${loginDomain.domain}/`}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="font-mono text-sm font-semibold underline-offset-4 hover:underline"
                                >
                                    https://{loginDomain.domain}/
                                </a>
                                <StatusBadge
                                    status={
                                        loginDomain.status === 'active'
                                            ? 'active'
                                            : loginDomain.status === 'failed'
                                              ? 'failed'
                                              : 'pending'
                                    }
                                    label={
                                        loginDomain.status === 'active'
                                            ? 'Aktif'
                                            : loginDomain.status === 'failed'
                                              ? 'Gagal'
                                              : 'Disiapkan'
                                    }
                                />
                            </div>
                            {loginDomain.error && (
                                <p className="text-sm text-destructive">
                                    {loginDomain.error}
                                </p>
                            )}
                        </div>
                    )}
                    <Form
                        {...updateLoginDomain.form()}
                        options={{ preserveScroll: true }}
                        className="grid gap-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="login_domain">Domain</Label>
                                    <Input
                                        id="login_domain"
                                        name="login_domain"
                                        autoComplete="off"
                                        defaultValue={loginDomain?.domain ?? ''}
                                        placeholder="panel.domainanda.com"
                                        className="font-mono"
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Arahkan record A domain ini ke{' '}
                                        {serverIp ?? 'alamat server'} dulu,
                                        tanpa proxy seperti Cloudflare, lalu
                                        simpan. Passkey yang dibuat di alamat
                                        panel lain tidak berlaku di domain ini;
                                        kata sandi dan 2FA tetap berlaku.
                                    </p>
                                    <InputError message={errors.login_domain} />
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    <Button type="submit" disabled={processing}>
                                        {processing ? <Spinner /> : <Check />}
                                        {loginDomain?.status === 'failed'
                                            ? 'Coba lagi'
                                            : 'Simpan domain'}
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                    {loginDomain && (
                        <Form
                            {...updateLoginDomain.form()}
                            options={{ preserveScroll: true }}
                            transform={() => ({ login_domain: '' })}
                        >
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    variant="outline"
                                    disabled={processing}
                                >
                                    <Trash2 /> Berhenti memakai domain ini
                                </Button>
                            )}
                        </Form>
                    )}
                </div>
            </Panel>

            <Panel
                title="Nameserver"
                description={`Nameserver yang tercantum di zone DNS semua akun Anda. Kosongkan untuk memakai nameserver panel: ${panelNameservers.join(', ')}.`}
            >
                <Form
                    {...updateNameservers.form()}
                    options={{ preserveScroll: true }}
                    className="grid max-w-2xl gap-5"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-5 md:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="ns1">Nameserver 1</Label>
                                    <Input
                                        id="ns1"
                                        name="ns1"
                                        autoComplete="off"
                                        defaultValue={nameservers[0] ?? ''}
                                        placeholder="ns1.domainanda.com"
                                        className="font-mono"
                                    />
                                    <InputError message={errors.ns1} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="ns2">Nameserver 2</Label>
                                    <Input
                                        id="ns2"
                                        name="ns2"
                                        autoComplete="off"
                                        defaultValue={nameservers[1] ?? ''}
                                        placeholder="ns2.domainanda.com"
                                        className="font-mono"
                                    />
                                    <InputError message={errors.ns2} />
                                </div>
                            </div>
                            <p className="text-sm text-muted-foreground">
                                Daftarkan nama-nama ini sebagai nameserver (glue
                                record) di registrar domain Anda
                                {serverIp ? `, dengan alamat ${serverIp}` : ''},
                                supaya domain pelanggan yang memakainya bisa
                                ditemukan.
                            </p>
                            <div>
                                <Button type="submit" disabled={processing}>
                                    {processing ? <Spinner /> : <Check />}
                                    Simpan nameserver
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </Panel>
        </>
    );
}
