import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { roleLabels } from '@/lib/format';
import { accountRoutes, packageRoutes } from '@/lib/scoped-routes';
import type { AccountScope } from '@/lib/scoped-routes';
import type { PackageOption, Role } from '@/types';

/** What each kind of account is, for the administrator's choice. */
const roleDescriptions: Record<Role, string> = {
    user: 'Akun hosting: website, email, database, dan file, dengan user Linux sendiri di server.',
    reseller:
        'Reseller: membuat dan mengelola akun hosting sendiri dalam jatah paket resellernya. Tidak punya website sendiri; untuk itu ia membuat akun hosting dan masuk sebagai akun itu.',
    admin: 'Administrator: mengelola seluruh server dan semua akun.',
};

export default function AccountsCreate({
    scope,
    passwordRules,
    packages,
    resellerPackages,
}: {
    scope: AccountScope;
    passwordRules: string;
    packages: PackageOption[];
    resellerPackages: PackageOption[] | null;
}) {
    const routes = accountRoutes(scope);
    const [role, setRole] = useState<Role>('user');
    const isHosting = role === 'user';

    return (
        <>
            <Head title="Buat akun" />

            <PageHeader
                title="Buat akun"
                description={
                    scope === 'admin'
                        ? 'Akun hosting, reseller, atau administrator baru. Server menyiapkan akun hosting di latar belakang; akun bisa dipakai masuk setelah statusnya aktif.'
                        : 'Akun hosting baru milik Anda. Server menyiapkannya di latar belakang, dan akun bisa dipakai masuk setelah statusnya aktif.'
                }
            />

            <Form
                {...routes.store.form()}
                resetOnError={['password', 'password_confirmation']}
                className="grid max-w-4xl gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <input type="hidden" name="role" value={role} />

                        {scope === 'admin' && (
                            <Panel
                                title="Jenis akun"
                                description={roleDescriptions[role]}
                            >
                                <div className="grid gap-2">
                                    <Label htmlFor="role">Jenis</Label>
                                    <Select
                                        value={role}
                                        onValueChange={(value) =>
                                            setRole(value as Role)
                                        }
                                    >
                                        <SelectTrigger
                                            id="role"
                                            className="w-full rounded-xl bg-card px-3.5 data-[size=default]:h-11 md:max-w-sm"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent className="rounded-xl">
                                            {(
                                                [
                                                    'user',
                                                    'reseller',
                                                    'admin',
                                                ] as Role[]
                                            ).map((option) => (
                                                <SelectItem
                                                    key={option}
                                                    value={option}
                                                >
                                                    {option === 'user'
                                                        ? 'Akun hosting'
                                                        : roleLabels[option]}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.role} />
                                </div>
                            </Panel>
                        )}

                        <Panel
                            title="Data akun"
                            description={
                                isHosting
                                    ? 'Username juga dipakai sebagai nama user Linux di server.'
                                    : 'Username dipakai untuk masuk ke panel.'
                            }
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

                        {role === 'reseller' && resellerPackages && (
                            <Panel
                                title="Paket reseller"
                                description="Jatah akun dan kuota yang boleh dibagikan reseller ini. Tanpa paket berarti tanpa batas."
                            >
                                <div className="grid gap-2">
                                    <Label htmlFor="reseller_package_id">
                                        Paket reseller
                                    </Label>
                                    <PackageSelect
                                        id="reseller_package_id"
                                        name="reseller_package_id"
                                        packages={resellerPackages}
                                        invalid={Boolean(
                                            errors.reseller_package_id,
                                        )}
                                    />
                                    <InputError
                                        message={errors.reseller_package_id}
                                    />
                                </div>
                            </Panel>
                        )}

                        {isHosting && (
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
                                            <TextLink
                                                href={packageRoutes(
                                                    scope,
                                                ).create()}
                                            >
                                                Buat paket
                                            </TextLink>{' '}
                                            untuk membatasi akun.
                                        </p>
                                    )}
                                    <InputError message={errors.package_id} />
                                </div>
                            </Panel>
                        )}

                        {isHosting && (
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
                        )}

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
                                <Link href={routes.index()}>Batal</Link>
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}
