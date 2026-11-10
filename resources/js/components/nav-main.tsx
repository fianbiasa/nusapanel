import { Link } from '@inertiajs/react';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import type { NavItem } from '@/types';

/**
 * One group of sidebar links. The active page gets the blue pill of the
 * reference design; in the collapsed rail only the icons remain.
 */
export function NavMain({ items, label }: { items: NavItem[]; label: string }) {
    const { isCurrentOrParentUrl } = useCurrentUrl();

    return (
        <SidebarGroup className="px-3 py-1">
            <SidebarGroupLabel className="px-3 text-[11px] font-semibold tracking-wider text-muted-foreground/80 uppercase">
                {label}
            </SidebarGroupLabel>
            <SidebarMenu className="gap-1">
                {items.map((item) => {
                    const active = isCurrentOrParentUrl(item.href);

                    return (
                        <SidebarMenuItem key={item.title}>
                            <SidebarMenuButton
                                asChild
                                isActive={active}
                                tooltip={{ children: item.title }}
                                className="h-11 gap-3 rounded-xl px-3.5 text-[15px] font-medium text-sidebar-foreground/85 transition-colors group-data-[collapsible=icon]:size-11! group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:p-0! data-[active=true]:rayxo-brand-gradient data-[active=true]:font-semibold data-[active=true]:text-white data-[active=true]:shadow-brand data-[active=true]:hover:text-white group-data-[collapsible=icon]:[&>span]:hidden [&>svg]:size-5"
                            >
                                <Link href={item.href} prefetch>
                                    {item.icon && (
                                        <item.icon strokeWidth={1.8} />
                                    )}
                                    <span>{item.title}</span>
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    );
                })}
            </SidebarMenu>
        </SidebarGroup>
    );
}
