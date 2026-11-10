import { AppContent } from '@/components/app-content';
import { AppDock } from '@/components/app-dock';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import type { AppLayoutProps } from '@/types';

/**
 * The signed-in layout: sidebar, top bar, the page itself and the dock.
 * Pages render their content only; spacing and width come from here.
 */
export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent
                variant="sidebar"
                className="rayxo-page-bg min-w-0 overflow-x-clip bg-background"
            >
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                <div className="mx-auto flex w-full max-w-[1440px] flex-1 flex-col gap-6 px-4 pt-2 pb-10 md:px-8 md:pb-32">
                    {children}
                </div>
                <AppDock />
            </AppContent>
        </AppShell>
    );
}
