import { Link } from '@inertiajs/react';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useNavigation } from '@/hooks/use-navigation';
import { cn } from '@/lib/utils';

/**
 * The floating quick-launch dock at the bottom of the reference design, with
 * the same pages as the sidebar for the account's role. It sticks to the
 * bottom of the content area (beside the sidebar, never over it), from
 * screens wide enough for every page of the menu.
 */
export function AppDock() {
    const { dockItems } = useNavigation();
    const { isCurrentOrParentUrl } = useCurrentUrl();

    return (
        <nav
            aria-label="Dock"
            className="pointer-events-none sticky bottom-4 z-30 hidden justify-center px-4 pb-4 lg:flex"
        >
            <ul className="pointer-events-auto flex items-end gap-0.5 rounded-2xl border bg-card/85 px-2 py-1.5 shadow-[0_12px_40px_rgb(36_77_139/0.16)] backdrop-blur-xl dark:shadow-[0_12px_40px_rgb(0_0_0/0.45)]">
                {dockItems.map((item) => {
                    const active = isCurrentOrParentUrl(item.href);

                    return (
                        <li key={item.title}>
                            <Link
                                href={item.href}
                                prefetch
                                className="group flex w-14 flex-col items-center gap-0.5 rounded-xl px-0.5 py-1 text-muted-foreground transition-colors hover:text-foreground"
                            >
                                <span
                                    className={cn(
                                        'flex size-9 items-center justify-center rounded-xl transition-all group-hover:-translate-y-0.5',
                                        active
                                            ? 'rayxo-brand-gradient text-white shadow-brand'
                                            : 'group-hover:bg-accent group-hover:text-accent-foreground',
                                    )}
                                >
                                    {item.icon && (
                                        <item.icon
                                            className="size-[18px]"
                                            strokeWidth={1.7}
                                        />
                                    )}
                                </span>
                                <span
                                    className={cn(
                                        'line-clamp-2 flex min-h-6 items-start text-center text-[10px] leading-3',
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
