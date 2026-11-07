import { Link } from '@inertiajs/react';
import { Globe, Server, ShieldCheck } from 'lucide-react';
import AppLogo, { AppLogoMark } from '@/components/app-logo';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

const highlights = [
    { icon: Server, text: 'Kelola server, akun hosting, dan website' },
    { icon: Globe, text: 'Domain, PHP, dan SSL dari satu tempat' },
    { icon: ShieldCheck, text: 'Setiap akun terisolasi dan aman' },
];

/**
 * Sign-in and account recovery pages: the form in a card, with the brand
 * panel of the reference design next to it on large screens.
 */
export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="nusa-page-bg flex min-h-svh bg-background">
            <aside className="relative hidden w-[44%] max-w-2xl flex-col justify-between overflow-hidden bg-[#0b1530] p-12 text-white lg:flex">
                <div className="pointer-events-none absolute -top-40 -right-40 size-[34rem] rounded-full bg-[#1688ff]/30 blur-3xl" />
                <div className="pointer-events-none absolute -bottom-48 -left-32 size-[30rem] rounded-full bg-[#9561f5]/20 blur-3xl" />

                <AppLogo className="relative text-white" />

                <div className="relative space-y-8">
                    <AppLogoMark className="size-28 drop-shadow-[0_20px_40px_rgb(22_136_255/0.45)]" />
                    <div className="space-y-3">
                        <h2 className="text-4xl leading-tight font-extrabold tracking-tight">
                            Panel hosting milik sendiri.
                        </h2>
                        <p className="max-w-md text-[15px] text-white/70">
                            Semua yang dibutuhkan untuk menjalankan server
                            hosting, tanpa biaya lisensi.
                        </p>
                    </div>
                    <ul className="space-y-3">
                        {highlights.map(({ icon: Icon, text }) => (
                            <li
                                key={text}
                                className="flex items-center gap-3 text-sm text-white/85"
                            >
                                <span className="flex size-9 items-center justify-center rounded-xl bg-white/10">
                                    <Icon className="size-[18px]" />
                                </span>
                                {text}
                            </li>
                        ))}
                    </ul>
                </div>

                <p className="relative text-xs text-white/50">
                    © {new Date().getFullYear()} Nusapanel
                </p>
            </aside>

            <main className="flex flex-1 items-center justify-center p-6 md:p-10">
                <div className="w-full max-w-md">
                    <div className="flex flex-col gap-8 rounded-2xl border bg-card p-8 shadow-card md:p-10">
                        <div className="flex flex-col items-center gap-5 text-center">
                            <Link
                                href={home()}
                                className="rounded-xl outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            >
                                <AppLogo />
                                <span className="sr-only">{title}</span>
                            </Link>
                            <div className="space-y-1.5">
                                <h1 className="text-2xl font-extrabold tracking-tight">
                                    {title}
                                </h1>
                                <p className="text-sm text-muted-foreground">
                                    {description}
                                </p>
                            </div>
                        </div>
                        {children}
                    </div>
                </div>
            </main>
        </div>
    );
}
