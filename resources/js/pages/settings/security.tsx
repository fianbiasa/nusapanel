import { Form, Head } from '@inertiajs/react';
import { History, LockKeyhole } from 'lucide-react';
import { useRef } from 'react';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import SettingsCard from '@/components/settings-card';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime, formatRelative } from '@/lib/format';
import { edit } from '@/routes/security';
import type { Props as ManagePasskeysProps } from '@/components/manage-passkeys';
import ManagePasskeys from '@/components/manage-passkeys';
import type { Props as ManageTwoFactorProps } from '@/components/manage-two-factor';
import ManageTwoFactor from '@/components/manage-two-factor';

type LoginEntry = {
    id: number;
    succeeded: boolean;
    ip_address: string | null;
    browser: string | null;
    os: string | null;
    created_at: string;
};

// oxfmt-ignore
type Props = {
    passwordRules: string;
    loginHistory: LoginEntry[];
} & ManagePasskeysProps &
    ManageTwoFactorProps;

export default function Security(props: Props) {
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);

    return (
        <>
            <Head title="Pengaturan keamanan" />

            <SettingsCard
                icon={LockKeyhole}
                title="Ganti kata sandi"
                description="Gunakan kata sandi yang panjang dan acak agar akun kamu tetap aman."
            >
                <Form
                    {...SecurityController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    resetOnError={[
                        'password',
                        'password_confirmation',
                        'current_password',
                    ]}
                    resetOnSuccess
                    onError={(errors) => {
                        if (errors.password) {
                            passwordInput.current?.focus();
                        }

                        if (errors.current_password) {
                            currentPasswordInput.current?.focus();
                        }
                    }}
                    className="flex flex-col gap-6"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-5">
                                <div className="grid gap-2 sm:max-w-[calc(50%-0.625rem)]">
                                    <Label htmlFor="current_password">
                                        Kata sandi saat ini
                                    </Label>

                                    <PasswordInput
                                        id="current_password"
                                        ref={currentPasswordInput}
                                        name="current_password"
                                        autoComplete="current-password"
                                        placeholder="Kata sandi saat ini"
                                    />

                                    <InputError
                                        message={errors.current_password}
                                    />
                                </div>

                                <div className="grid gap-5 sm:grid-cols-2">
                                    <div className="grid content-start gap-2">
                                        <Label htmlFor="password">
                                            Kata sandi baru
                                        </Label>

                                        <PasswordInput
                                            id="password"
                                            ref={passwordInput}
                                            name="password"
                                            autoComplete="new-password"
                                            placeholder="Kata sandi baru"
                                            passwordrules={props.passwordRules}
                                        />

                                        <InputError message={errors.password} />
                                    </div>

                                    <div className="grid content-start gap-2">
                                        <Label htmlFor="password_confirmation">
                                            Konfirmasi kata sandi baru
                                        </Label>

                                        <PasswordInput
                                            id="password_confirmation"
                                            name="password_confirmation"
                                            autoComplete="new-password"
                                            placeholder="Ulangi kata sandi baru"
                                            passwordrules={props.passwordRules}
                                        />

                                        <InputError
                                            message={
                                                errors.password_confirmation
                                            }
                                        />
                                    </div>
                                </div>
                            </div>

                            <div className="flex items-center justify-end border-t pt-5">
                                <Button
                                    disabled={processing}
                                    data-test="update-password-button"
                                >
                                    {processing && <Spinner />}
                                    Simpan
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </SettingsCard>

            <ManageTwoFactor
                canManageTwoFactor={props.canManageTwoFactor}
                requiresConfirmation={props.requiresConfirmation}
                twoFactorEnabled={props.twoFactorEnabled}
            />

            <ManagePasskeys
                canManagePasskeys={props.canManagePasskeys}
                passkeys={props.passkeys}
            />

            <SettingsCard
                icon={History}
                title="Riwayat login"
                description="Login terakhir ke akun ini, termasuk yang gagal. Kalau ada yang tidak kamu kenal, ganti kata sandi dan aktifkan 2FA."
            >
                {props.loginHistory.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Belum ada login yang tercatat.
                    </p>
                ) : (
                    <ul className="divide-y text-sm">
                        {props.loginHistory.map((entry) => (
                            <li
                                key={entry.id}
                                className="flex flex-wrap items-center gap-x-4 gap-y-1 py-3"
                            >
                                <StatusBadge
                                    status={
                                        entry.succeeded ? 'active' : 'failed'
                                    }
                                    label={
                                        entry.succeeded ? 'Berhasil' : 'Gagal'
                                    }
                                />
                                <span className="font-medium">
                                    {[entry.browser, entry.os]
                                        .filter(Boolean)
                                        .join(' · ') ||
                                        'Perangkat tidak dikenal'}
                                </span>
                                <span className="font-mono text-xs text-muted-foreground">
                                    {entry.ip_address ?? '—'}
                                </span>
                                <span
                                    className="ml-auto text-muted-foreground"
                                    title={formatDateTime(entry.created_at)}
                                >
                                    {formatRelative(entry.created_at)}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </SettingsCard>
        </>
    );
}

Security.layout = {
    breadcrumbs: [
        {
            title: 'Pengaturan keamanan',
            href: edit(),
        },
    ],
};
