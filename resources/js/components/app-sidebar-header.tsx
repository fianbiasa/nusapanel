import { usePage } from '@inertiajs/react';
import { ChevronDown, Moon, Sun } from 'lucide-react';
import { CommandSearch } from '@/components/command-search';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { UserInfo } from '@/components/user-info';
import { UserMenuContent } from '@/components/user-menu-content';
import { useAppearance } from '@/hooks/use-appearance';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

/**
 * The top bar: search, light/dark switch and the account menu. Pages show
 * their own title below it, so breadcrumbs are accepted but not displayed.
 */
export function AppSidebarHeader(_props: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const { auth } = usePage().props;
    const { resolvedAppearance, updateAppearance } = useAppearance();
    const dark = resolvedAppearance === 'dark';

    return (
        <header className="flex h-20 shrink-0 items-center gap-3 px-4 md:px-8">
            <SidebarTrigger className="size-10 rounded-xl md:hidden" />

            <CommandSearch />

            <div className="ml-auto flex items-center gap-2">
                <button
                    type="button"
                    onClick={() => updateAppearance(dark ? 'light' : 'dark')}
                    aria-label={dark ? 'Pakai tema terang' : 'Pakai tema gelap'}
                    className="flex size-11 items-center justify-center rounded-xl text-muted-foreground transition-colors hover:bg-card hover:text-foreground hover:shadow-card"
                >
                    {dark ? (
                        <Sun className="size-5" />
                    ) : (
                        <Moon className="size-5" />
                    )}
                </button>

                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <button
                            type="button"
                            className="hidden h-14 items-center gap-3 rounded-2xl border bg-card px-3 shadow-card transition-colors hover:border-primary/40 sm:flex"
                        >
                            <UserInfo user={auth.user} showRole />
                            <ChevronDown className="size-4 text-muted-foreground" />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        align="end"
                        className="min-w-56 rounded-xl"
                    >
                        <UserMenuContent user={auth.user} />
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </header>
    );
}
