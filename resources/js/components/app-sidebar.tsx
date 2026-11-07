import { Link } from '@inertiajs/react';
import { ChevronsLeft, ChevronsRight } from 'lucide-react';
import AppLogo, { AppLogoMark } from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    useSidebar,
} from '@/components/ui/sidebar';
import { useNavigation } from '@/hooks/use-navigation';
import { dashboard } from '@/routes';

export function AppSidebar() {
    const { sections } = useNavigation();
    const { toggleSidebar, state } = useSidebar();
    const collapsed = state === 'collapsed';

    return (
        <Sidebar collapsible="icon" className="border-r-sidebar-border">
            <SidebarHeader className="px-4 pt-6 pb-4 group-data-[collapsible=icon]:items-center group-data-[collapsible=icon]:px-2">
                <div className="flex items-center justify-between gap-2 group-data-[collapsible=icon]:flex-col">
                    <Link
                        href={dashboard()}
                        prefetch
                        className="rounded-xl outline-none focus-visible:ring-2 focus-visible:ring-sidebar-ring"
                    >
                        {collapsed ? (
                            <AppLogoMark className="size-10" />
                        ) : (
                            <AppLogo />
                        )}
                    </Link>
                    <button
                        type="button"
                        onClick={toggleSidebar}
                        aria-label={
                            collapsed ? 'Lebarkan sidebar' : 'Ciutkan sidebar'
                        }
                        className="hidden size-9 shrink-0 items-center justify-center rounded-xl bg-muted text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground md:flex"
                    >
                        {collapsed ? (
                            <ChevronsRight className="size-4" />
                        ) : (
                            <ChevronsLeft className="size-4" />
                        )}
                    </button>
                </div>
            </SidebarHeader>

            <SidebarContent className="gap-3 py-2">
                {sections.map((section) => (
                    <NavMain
                        key={section.label}
                        label={section.label}
                        items={section.items}
                    />
                ))}
            </SidebarContent>

            <SidebarFooter className="p-4 group-data-[collapsible=icon]:items-center group-data-[collapsible=icon]:p-2">
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
