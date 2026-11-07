import { router } from '@inertiajs/react';
import { Fingerprint, KeyRound } from 'lucide-react';
import { destroy } from '@/actions/Laravel/Passkeys/Http/Controllers/PasskeyRegistrationController';
import PasskeyItem from '@/components/passkey-item';
import PasskeyRegistration from '@/components/passkey-register';
import SettingsCard from '@/components/settings-card';
import type { Passkey } from '@/types/auth';

export type Props = {
    canManagePasskeys?: boolean;
    passkeys?: Passkey[];
};

const EmptyState = () => {
    return (
        <div className="flex flex-col items-center gap-3 px-6 py-8 text-center">
            <span className="flex size-14 items-center justify-center rounded-full bg-accent text-accent-foreground">
                <KeyRound className="size-6" />
            </span>
            <div className="space-y-1">
                <p className="font-semibold">Belum ada passkey</p>
                <p className="text-sm text-muted-foreground">
                    Tambahkan passkey untuk masuk tanpa kata sandi.
                </p>
            </div>
        </div>
    );
};

export default function ManagePasskeys(props: Props) {
    const passkeys = props.passkeys ?? [];

    const handleDelete = (id: number, onError: () => void) => {
        router.delete(destroy.url(id), {
            preserveScroll: true,
            onError,
        });
    };

    const handleRegisterSuccess = () => {
        router.reload();
    };

    if (!(props.canManagePasskeys ?? false)) {
        return null;
    }

    return (
        <SettingsCard
            icon={Fingerprint}
            title="Passkey"
            description="Masuk tanpa kata sandi memakai sidik jari, wajah, atau kunci keamanan perangkat kamu."
        >
            <div className="overflow-hidden rounded-xl border">
                {passkeys.length > 0 ? (
                    passkeys.map((passkey) => (
                        <PasskeyItem
                            key={passkey.id}
                            passkey={passkey}
                            onDelete={handleDelete}
                        />
                    ))
                ) : (
                    <EmptyState />
                )}
            </div>

            <PasskeyRegistration onSuccess={handleRegisterSuccess} />
        </SettingsCard>
    );
}
