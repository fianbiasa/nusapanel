import { Link, usePage } from '@inertiajs/react';
import { Globe, LayoutGrid, ListChecks, ScrollText, Users } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index as accountsIndex } from '@/routes/admin/accounts';
import { index as auditLogsIndex } from '@/routes/admin/audit-logs';
import { index as tasksIndex } from '@/routes/admin/tasks';
import { index as domainsIndex } from '@/routes/domains';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
];

// Hosting accounts only: admins and resellers have no websites of their own.
const hostingNavItems: NavItem[] = [
    {
        title: 'Domains',
        href: domainsIndex(),
        icon: Globe,
    },
];

const adminNavItems: NavItem[] = [
    {
        title: 'Accounts',
        href: accountsIndex(),
        icon: Users,
    },
    {
        title: 'Tasks',
        href: tasksIndex(),
        icon: ListChecks,
    },
    {
        title: 'Audit log',
        href: auditLogsIndex(),
        icon: ScrollText,
    },
];

export function AppSidebar() {
    const { auth } = usePage().props;

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain
                    label="Panel"
                    items={
                        auth.user.role === 'user'
                            ? [...mainNavItems, ...hostingNavItems]
                            : mainNavItems
                    }
                />
                {auth.user.role === 'admin' && (
                    <NavMain
                        label="Server administration"
                        items={adminNavItems}
                    />
                )}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
