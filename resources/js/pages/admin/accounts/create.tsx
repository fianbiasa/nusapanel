import { Form, Head, Link } from '@inertiajs/react';
import { UserPlus } from 'lucide-react';
import InputError from '@/components/input-error';
import { PackageSelect } from '@/components/package-select';
import { PageHeader } from '@/components/page-header';
import { Panel } from '@/components/panel';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { index, store } from '@/routes/admin/accounts';
import { create as packagesCreate } from '@/routes/admin/packages';
import type { PackageOption } from '@/types';

export default function AccountsCreate({
    passwordRules,
    packages,
}: {
    passwordRules: string;
    packages: PackageOption[];
}) {
    return (
        <>
            <Head title="Buat akun" />

            <PageHeader
                title="Buat akun"
                description="Akun hosting baru. Server menyiapkannya di latar belakang, dan akun bisa dipakai masuk setelah statusnya aktif."
            />

            <Form
                {...store.form()}
                resetOnError={['password', 'password_confirmation']}
                className="grid max-w-4xl gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <Panel
                            title="Data akun"
                            description="Username juga dipakai sebagai nama user Linux di server."
                        >
                            <div className="grid gap-5 md:grid-cols-2">
                                <div className="grid gap-2 md:col-span-2">
                                    <Label htmlFor="username">Username</Label>
                                    <Input
                                        id="username"
                                        name="username"
                                        required
                                        autoFocus
                                        autoComplete="off"
                                        autoCapitalize="none"
                                        spellCheck={false}
                                        maxLength={16}
                                        placeholder="mis. budi"
                                        className="md:max-w-sm"
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        3–16 huruf kecil dan angka, diawali
                                        huruf. Tidak bisa diganti setelah akun
                                        dibuat.
                                    </p>
                                    <InputError message={errors.username} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="name">Nama</Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        required
                                        autoComplete="off"
                                        placeholder="Nama lengkap"
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="email">Email</Label>
                                    <Input
                                        id="email"
                                        type="email"
                                        name="email"
                                        required
                                        autoComplete="off"
                                        placeholder="email@contoh.com"
                                    />
                                    <InputError message={errors.email} />
                                </div>
                            </div>
                        </Panel>

                        <Panel
                            title="Paket"
                            description="Batas yang berlaku untuk akun ini. Tanpa paket berarti tanpa batas."
                        >
                            <div className="grid gap-2">
                                <Label htmlFor="package_id">Paket</Label>
                                <PackageSelect
                                    id="package_id"
                                    packages={packages}
                                    invalid={Boolean(errors.package_id)}
                                />
                                {packages.length === 0 && (
                                    <p className="text-xs text-muted-foreground">
                                        Belum ada paket.{' '}
                                        <TextLink href={packagesCreate()}>
                                            Buat paket
                                        </TextLink>{' '}
                                        untuk membatasi akun.
                                    </p>
                                )}
                                <InputError message={errors.package_id} />
                            </div>
                        </Panel>

                        <Panel
                            title="Website"
                            description="Opsional. Domain utama disiapkan sebagai website pertama begitu akun selesai dibuat."
                        >
                            <div className="grid gap-2">
                                <Label htmlFor="domain">Domain utama</Label>
                                <Input
                                    id="domain"
                                    name="domain"
                                    autoComplete="off"
                                    autoCapitalize="none"
                                    spellCheck={false}
                                    maxLength={253}
                                    placeholder="contoh.com"
                                    className="md:max-w-md"
                                />
                                <p className="text-xs text-muted-foreground">
                                    Tulis tanpa www, karena www ikut aktif
                                    otomatis.
                                </p>
                                <InputError message={errors.domain} />
                            </div>
                        </Panel>

                        <Panel
                            title="Kata sandi"
                            description="Dipakai untuk masuk ke panel."
                        >
                            <div className="grid gap-5 md:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="password">Kata sandi</Label>
                                    <PasswordInput
                                        id="password"
                                        name="password"
                                        required
                                        autoComplete="new-password"
                                        placeholder="Kata sandi"
                                        passwordrules={passwordRules}
                                    />
                                    <InputError message={errors.password} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="password_confirmation">
                                        Ulangi kata sandi
                                    </Label>
                                    <PasswordInput
                                        id="password_confirmation"
                                        name="password_confirmation"
                                        required
                                        autoComplete="new-password"
                                        placeholder="Ulangi kata sandi"
                                        passwordrules={passwordRules}
                                    />
                                    <InputError
                                        message={errors.password_confirmation}
                                    />
                                </div>
                            </div>
                        </Panel>

                        <div className="flex items-center gap-2">
                            <Button
                                type="submit"
                                size="lg"
                                disabled={processing}
                            >
                                {processing ? <Spinner /> : <UserPlus />}
                                Buat akun
                            </Button>
                            <Button variant="ghost" size="lg" asChild>
                                <Link href={index()}>Batal</Link>
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}
