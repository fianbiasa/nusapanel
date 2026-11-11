import { usePage } from '@inertiajs/react';
import {
    Activity,
    Clock,
    Database,
    FolderOpen,
    FolderUp,
    Globe,
    House,
    Milestone,
    ListChecks,
    Network,
    Package,
    ScrollText,
    Settings,
    SlidersHorizontal,
    Users,
} from 'lucide-react';
import { dashboard } from '@/routes';
import { index as cronJobsIndex } from '@/routes/cron-jobs';
import { index as databasesIndex } from '@/routes/databases';
import { index as dnsIndex } from '@/routes/dns';
import { index as accountsIndex } from '@/routes/admin/accounts';
import { index as auditLogsIndex } from '@/routes/admin/audit-logs';
import { index as adminDnsIndex } from '@/routes/admin/dns';
import { index as packagesIndex } from '@/routes/admin/packages';
import { index as serverSettingsIndex } from '@/routes/admin/server-settings';
import { index as servicesIndex } from '@/routes/admin/services';
import { index as tasksIndex } from '@/routes/admin/tasks';
import { index as domainsIndex } from '@/routes/domains';
import { index as filesIndex } from '@/routes/files';
import { index as ftpIndex } from '@/routes/ftp';
import { edit as profileEdit } from '@/routes/profile';
import { index as redirectsIndex } from '@/routes/redirects';
import type { NavItem } from '@/types';

export type NavSection = {
    label: string;
    items: NavItem[];
};

const dashboardItem: NavItem = {
    title: 'Dashboard',
    href: dashboard(),
    icon: House,
};

const settingsItem: NavItem = {
    title: 'Settings',
    href: profileEdit(),
    icon: Settings,
};

/**
 * The menu for the signed-in account's role. The sidebar, the dock and the
 * search all read it, so they always offer the same pages. Only pages that
 * exist are listed; new features add their entry here.
 */
export function useNavigation(): {
    sections: NavSection[];
    dockItems: NavItem[];
    allItems: NavItem[];
} {
    const { auth } = usePage().props;
    const role = auth.user.role;

    const sections: NavSection[] = [];
    let dockItems: NavItem[];

    if (role === 'admin') {
        const accounts: NavItem = {
            title: 'Accounts',
            href: accountsIndex(),
            icon: Users,
        };
        const adminDns: NavItem = {
            title: 'DNS',
            href: adminDnsIndex(),
            icon: Network,
        };
        const packages: NavItem = {
            title: 'Packages',
            href: packagesIndex(),
            icon: Package,
        };
        const tasks: NavItem = {
            title: 'Tasks',
            href: tasksIndex(),
            icon: ListChecks,
        };
        const auditLog: NavItem = {
            title: 'Audit Log',
            href: auditLogsIndex(),
            icon: ScrollText,
        };
        const services: NavItem = {
            title: 'Services',
            href: servicesIndex(),
            icon: Activity,
        };
        const serverSettings: NavItem = {
            title: 'Pengaturan Server',
            href: serverSettingsIndex(),
            icon: SlidersHorizontal,
        };

        sections.push(
            {
                label: 'Menu',
                items: [dashboardItem, accounts, packages, adminDns],
            },
            {
                label: 'Server',
                items: [services, serverSettings, tasks, auditLog],
            },
        );
        dockItems = [
            dashboardItem,
            accounts,
            packages,
            tasks,
            auditLog,
            settingsItem,
        ];
    } else if (role === 'user') {
        const domains: NavItem = {
            title: 'Domains',
            href: domainsIndex(),
            icon: Globe,
        };

        const dns: NavItem = {
            title: 'DNS',
            href: dnsIndex(),
            icon: Network,
        };
        const databases: NavItem = {
            title: 'Databases',
            href: databasesIndex(),
            icon: Database,
        };
        const files: NavItem = {
            title: 'File Manager',
            href: filesIndex(),
            icon: FolderOpen,
        };
        const ftp: NavItem = {
            title: 'FTP',
            href: ftpIndex(),
            icon: FolderUp,
        };
        const cronJobs: NavItem = {
            title: 'Cron Jobs',
            href: cronJobsIndex(),
            icon: Clock,
        };
        const redirects: NavItem = {
            title: 'Redirects',
            href: redirectsIndex(),
            icon: Milestone,
        };

        sections.push({
            label: 'Menu',
            items: [
                dashboardItem,
                domains,
                dns,
                files,
                databases,
                ftp,
                cronJobs,
                redirects,
            ],
        });
        dockItems = [
            dashboardItem,
            domains,
            files,
            databases,
            ftp,
            settingsItem,
        ];
    } else {
        sections.push({ label: 'Menu', items: [dashboardItem] });
        dockItems = [dashboardItem, settingsItem];
    }

    sections.push({ label: 'Akun', items: [settingsItem] });

    return {
        sections,
        dockItems,
        allItems: sections.flatMap((section) => section.items),
    };
}
