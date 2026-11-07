import { Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * A white card with a bold title, as every block of the reference design.
 * `flush` drops the inner padding below the title, for tables that run to
 * the card's edges.
 */
export function Panel({
    title,
    description,
    action,
    className,
    flush = false,
    children,
}: {
    title?: ReactNode;
    description?: ReactNode;
    action?: ReactNode;
    className?: string;
    flush?: boolean;
    children: ReactNode;
}) {
    return (
        <section
            className={cn(
                'flex flex-col overflow-hidden rounded-2xl border bg-card shadow-card',
                className,
            )}
        >
            {(title || action) && (
                <div className="flex items-start justify-between gap-4 px-6 pt-6 pb-1">
                    <div className="space-y-1">
                        {title && (
                            <h2 className="text-lg font-bold tracking-tight">
                                {title}
                            </h2>
                        )}
                        {description && (
                            <p className="text-sm text-muted-foreground">
                                {description}
                            </p>
                        )}
                    </div>
                    {action}
                </div>
            )}
            <div className={cn(flush ? 'pt-4' : 'p-6 pt-5')}>{children}</div>
        </section>
    );
}

/** The "Lihat semua →" link in a panel's title row. */
export function PanelLink({
    href,
    children,
}: {
    href: NonNullable<InertiaLinkProps['href']>;
    children: ReactNode;
}) {
    return (
        <Link
            href={href}
            prefetch
            className="inline-flex shrink-0 items-center gap-1 text-sm font-semibold text-primary hover:underline"
        >
            {children} <ArrowRight className="size-4" />
        </Link>
    );
}

/** Shared look of the data tables inside a flush panel. */
export const tableStyles = {
    table: 'w-full text-sm',
    head: 'border-y bg-muted/50 text-left text-xs font-semibold tracking-wide text-muted-foreground uppercase',
    th: 'px-6 py-3 font-semibold',
    row: 'transition-colors hover:bg-muted/40',
    td: 'px-6 py-4',
};
