import { Link } from '@inertiajs/react';
import { Palette, ShieldCheck, UserRound } from 'lucide-react';
import type { PropsWithChildren } from 'react';
import { PageHeader } from '@/components/page-header';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn, toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

const sidebarNavItems: NavItem[] = [
    {
        title: 'Profil',
        href: edit(),
        icon: UserRound,
    },
    {
        title: 'Keamanan',
        href: editSecurity(),
        icon: ShieldCheck,
    },
    {
        title: 'Tampilan',
        href: editAppearance(),
        icon: Palette,
    },
];

/**
 * The settings pages: the page title, a pill navigation on the left and the
 * page's cards on the right.
 */
export default function SettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();

    return (
        <>
            <PageHeader
                title="Pengaturan"
                description="Kelola profil dan keamanan akun kamu."
            />

            <div className="flex flex-col gap-6 xl:flex-row xl:items-start xl:gap-8">
                <aside className="w-full shrink-0 xl:w-60">
                    <nav
                        className="flex w-fit max-w-full gap-1 overflow-x-auto rounded-2xl border bg-card p-2 shadow-card xl:w-full xl:flex-col"
                        aria-label="Pengaturan"
                    >
                        {sidebarNavItems.map((item, index) => {
                            const isActive = isCurrentOrParentUrl(item.href);

                            return (
                                <Link
                                    key={`${toUrl(item.href)}-${index}`}
                                    href={item.href}
                                    aria-current={isActive ? 'page' : undefined}
                                    className={cn(
                                        'flex shrink-0 items-center gap-3 rounded-xl px-3.5 py-2.5 text-sm font-medium whitespace-nowrap text-muted-foreground transition-colors outline-none hover:bg-muted hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50',
                                        isActive &&
                                            'bg-accent font-semibold text-accent-foreground hover:bg-accent hover:text-accent-foreground',
                                    )}
                                >
                                    {item.icon && (
                                        <item.icon className="size-[18px]" />
                                    )}
                                    {item.title}
                                </Link>
                            );
                        })}
                    </nav>
                </aside>

                <div className="flex w-full max-w-3xl min-w-0 flex-1 flex-col gap-6">
                    {children}
                </div>
            </div>
        </>
    );
}
