import { Link } from '@inertiajs/react';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useNavigation } from '@/hooks/use-navigation';
import { cn } from '@/lib/utils';

/**
 * The floating quick-launch dock at the bottom of the reference design.
 * It offers the same pages as the sidebar, for the account's role.
 */
export function AppDock() {
    const { dockItems } = useNavigation();
    const { isCurrentOrParentUrl } = useCurrentUrl();

    return (
        <nav
            aria-label="Dock"
            className="pointer-events-none fixed inset-x-0 bottom-4 z-30 hidden justify-center md:flex"
        >
            <ul className="pointer-events-auto flex items-end gap-1 rounded-3xl border bg-card/85 px-3 py-2 shadow-[0_12px_40px_rgb(36_77_139/0.16)] backdrop-blur-xl dark:shadow-[0_12px_40px_rgb(0_0_0/0.45)]">
                {dockItems.map((item) => {
                    const active = isCurrentOrParentUrl(item.href);

                    return (
                        <li key={item.title}>
                            <Link
                                href={item.href}
                                prefetch
                                className="group flex w-20 flex-col items-center gap-1 rounded-2xl px-1 py-1.5 text-muted-foreground transition-colors hover:text-foreground"
                            >
                                <span
                                    className={cn(
                                        'flex size-12 items-center justify-center rounded-2xl transition-all group-hover:-translate-y-0.5',
                                        active
                                            ? 'rayxo-brand-gradient text-white shadow-brand'
                                            : 'group-hover:bg-accent group-hover:text-accent-foreground',
                                    )}
                                >
                                    {item.icon && (
                                        <item.icon
                                            className="size-6"
                                            strokeWidth={1.7}
                                        />
                                    )}
                                </span>
                                <span
                                    className={cn(
                                        'truncate text-xs',
                                        active
                                            ? 'font-semibold text-primary'
                                            : 'font-medium',
                                    )}
                                >
                                    {item.title}
                                </span>
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}
