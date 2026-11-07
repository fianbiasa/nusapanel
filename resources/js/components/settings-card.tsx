import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import Heading from '@/components/heading';
import { cn } from '@/lib/utils';

/**
 * One section of the settings pages: a card with an icon, a bold title and a
 * muted description, styled like the panels on the dashboard.
 */
export default function SettingsCard({
    icon: Icon,
    title,
    description,
    badge,
    className,
    children,
}: {
    icon?: LucideIcon;
    title: string;
    description?: string;
    badge?: ReactNode;
    className?: string;
    children: ReactNode;
}) {
    return (
        <section
            className={cn(
                'flex flex-col gap-6 rounded-2xl border bg-card p-6 shadow-card',
                className,
            )}
        >
            <div className="flex items-start gap-4">
                {Icon && (
                    <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-accent text-accent-foreground">
                        <Icon className="size-5" />
                    </span>
                )}
                <div className="flex min-w-0 flex-1 flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <Heading
                        variant="small"
                        title={title}
                        description={description}
                    />
                    {badge && <div className="shrink-0">{badge}</div>}
                </div>
            </div>
            {children}
        </section>
    );
}
