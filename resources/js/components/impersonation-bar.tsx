import { Form, usePage } from '@inertiajs/react';
import { LogOut, UserRoundCog } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { destroy } from '@/routes/impersonation';

/**
 * While an administrator or reseller is signed in as another account
 * ("login as"), says so above every page and leads back to their own.
 */
export function ImpersonationBar() {
    const { auth } = usePage().props;

    if (auth.impersonator === null) {
        return null;
    }

    return (
        <div className="mx-4 mt-3 flex flex-wrap items-center gap-3 rounded-2xl border border-amber-300 bg-amber-50 px-4 py-2.5 text-sm text-amber-950 md:mx-8 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-100">
            <UserRoundCog className="size-4 shrink-0" />
            <p className="mr-auto">
                Anda masuk sebagai{' '}
                <span className="font-semibold">{auth.user.username}</span>.
                Semua tindakan tercatat atas nama{' '}
                <span className="font-semibold">
                    {auth.impersonator.username}
                </span>
                .
            </p>
            <Form {...destroy.form()}>
                {({ processing }) => (
                    <Button
                        type="submit"
                        size="sm"
                        variant="outline"
                        disabled={processing}
                        className="bg-background"
                    >
                        {processing ? <Spinner /> : <LogOut />}
                        Kembali ke {auth.impersonator?.username}
                    </Button>
                )}
            </Form>
        </div>
    );
}
