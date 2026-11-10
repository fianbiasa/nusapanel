// Components
import { Form, Head } from '@inertiajs/react';
import { CircleCheck } from 'lucide-react';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { email } from '@/routes/password';

export default function ForgotPassword({ status }: { status?: string }) {
    return (
        <>
            <Head title="Lupa kata sandi" />

            {status && (
                <div className="flex items-start gap-2.5 rounded-xl bg-success/10 px-4 py-3 text-sm font-medium text-success-foreground">
                    <CircleCheck className="mt-0.5 size-4 shrink-0" />
                    {status}
                </div>
            )}

            <div className="flex flex-col gap-6">
                <Form {...email.form()} className="flex flex-col gap-6">
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="email">Email</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    autoComplete="off"
                                    autoFocus
                                    placeholder="nama@email.com"
                                />

                                <InputError message={errors.email} />
                            </div>

                            <Button
                                className="h-11 w-full rounded-xl rayxo-brand-gradient text-[15px] font-semibold shadow-brand"
                                disabled={processing}
                                data-test="email-password-reset-link-button"
                            >
                                {processing && <Spinner />}
                                Kirim tautan reset kata sandi
                            </Button>
                        </>
                    )}
                </Form>

                <p className="text-center text-sm text-muted-foreground">
                    Sudah ingat?{' '}
                    <TextLink href={login()}>Kembali ke halaman masuk</TextLink>
                </p>
            </div>
        </>
    );
}

ForgotPassword.layout = {
    title: 'Lupa kata sandi?',
    description:
        'Masukkan email akun kamu, kami akan mengirim tautan untuk mengatur ulang kata sandi.',
};
