// Components
import { Form, Head } from '@inertiajs/react';
import { CircleCheck } from 'lucide-react';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

export default function VerifyEmail({ status }: { status?: string }) {
    return (
        <>
            <Head title="Verifikasi email" />

            {status === 'verification-link-sent' && (
                <div className="flex items-start gap-2.5 rounded-xl bg-success/10 px-4 py-3 text-sm font-medium text-success-foreground">
                    <CircleCheck className="mt-0.5 size-4 shrink-0" />
                    Tautan verifikasi baru sudah dikirim ke alamat email yang
                    kamu daftarkan.
                </div>
            )}

            <Form {...send.form()} className="flex flex-col gap-5 text-center">
                {({ processing }) => (
                    <>
                        <Button
                            disabled={processing}
                            className="h-11 w-full rounded-xl rayxo-brand-gradient text-[15px] font-semibold shadow-brand"
                        >
                            {processing && <Spinner />}
                            Kirim ulang email verifikasi
                        </Button>

                        <TextLink
                            href={logout()}
                            className="mx-auto block text-sm"
                        >
                            Keluar
                        </TextLink>
                    </>
                )}
            </Form>
        </>
    );
}

VerifyEmail.layout = {
    title: 'Verifikasi email',
    description:
        'Verifikasi alamat email kamu lewat tautan yang baru saja kami kirim.',
};
