import { usePage } from '@inertiajs/react';
import {
    Activity,
    ChartColumn,
    Boxes,
    Clock,
    Database,
    FolderOpen,
    FolderUp,
    Globe,
    House,
    Milestone,
    ListChecks,
    Mail,
    MailWarning,
    Network,
    Package,
    ScrollText,
    Settings,
    SlidersHorizontal,
    SquareTerminal,
    Users,
} from 'lucide-react';
import { dashboard } from '@/routes';
import { index as cronJobsIndex } from '@/routes/cron-jobs';
import { index as databasesIndex } from '@/routes/databases';
import { index as dnsIndex } from '@/routes/dns';
import { index as accountsIndex } from '@/routes/admin/accounts';
import { index as auditLogsIndex } from '@/routes/admin/audit-logs';
import { index as adminDnsIndex } from '@/routes/admin/dns';
import { index as mailQueueIndex } from '@/routes/admin/mail-queue';
import { index as packagesIndex } from '@/routes/admin/packages';
import { index as resellerPackagesIndex } from '@/routes/admin/reseller-packages';
import { index as serverSettingsIndex } from '@/routes/admin/server-settings';
import { index as servicesIndex } from '@/routes/admin/services';
import { index as statisticsIndex } from '@/routes/admin/statistics';
import { index as tasksIndex } from '@/routes/admin/tasks';
import { index as resellerAccountsIndex } from '@/routes/reseller/accounts';
import { index as resellerOwnPackagesIndex } from '@/routes/reseller/packages';
import { index as resellerStatisticsIndex } from '@/routes/reseller/statistics';
import { index as domainsIndex } from '@/routes/domains';
import { index as emailIndex } from '@/routes/email';
import { index as filesIndex } from '@/routes/files';
import { index as ftpIndex } from '@/routes/ftp';
import { edit as profileEdit } from '@/routes/profile';
import { index as redirectsIndex } from '@/routes/redirects';
import { index as sshIndex } from '@/routes/ssh';
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
 * search all read it, so they always offer the same pages, in the same
 * order. Only pages that exist are listed; new features add their entry here.
 */
export function useNavigation(): {
    sections: NavSection[];
    dockItems: NavItem[];
    allItems: NavItem[];
} {
    const { auth } = usePage().props;
    const role = auth.user.role;

    const sections: NavSection[] = [];

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
        const resellerPackages: NavItem = {
            title: 'Paket Reseller',
            href: resellerPackagesIndex(),
            icon: Boxes,
        };
        const statistics: NavItem = {
            title: 'Statistik',
            href: statisticsIndex(),
            icon: ChartColumn,
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
        const mailQueue: NavItem = {
            title: 'Antrean Email',
            href: mailQueueIndex(),
            icon: MailWarning,
        };
        const serverSettings: NavItem = {
            title: 'Pengaturan Server',
            href: serverSettingsIndex(),
            icon: SlidersHorizontal,
        };

        sections.push(
            {
                label: 'Menu',
                items: [
                    dashboardItem,
                    accounts,
                    packages,
                    resellerPackages,
                    adminDns,
                    statistics,
                ],
            },
            {
                label: 'Server',
                items: [services, mailQueue, serverSettings, tasks, auditLog],
            },
        );
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
        const email: NavItem = {
            title: 'Email',
            href: emailIndex(),
            icon: Mail,
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
        const ssh: NavItem = {
            title: 'SSH',
            href: sshIndex(),
            icon: SquareTerminal,
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
                email,
                files,
                databases,
                ftp,
                ssh,
                cronJobs,
                redirects,
            ],
        });
    } else if (role === 'reseller') {
        sections.push({
            label: 'Menu',
            items: [
                dashboardItem,
                {
                    title: 'Accounts',
                    href: resellerAccountsIndex(),
                    icon: Users,
                },
                {
                    title: 'Packages',
                    href: resellerOwnPackagesIndex(),
                    icon: Package,
                },
                {
                    title: 'Statistik',
                    href: resellerStatisticsIndex(),
                    icon: ChartColumn,
                },
            ],
        });
    } else {
        sections.push({ label: 'Menu', items: [dashboardItem] });
    }

    sections.push({ label: 'Akun', items: [settingsItem] });

    const allItems = sections.flatMap((section) => section.items);

    return { sections, dockItems: allItems, allItems };
}
