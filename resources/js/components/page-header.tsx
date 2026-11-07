import type { ReactNode } from 'react';

/**
 * The large title at the top of every page, as in the reference design
 * ("Halo, Fian" with a line of explanation), with optional actions on the
 * right.
 */
export function PageHeader({
    title,
    description,
    actions,
}: {
    title: ReactNode;
    description?: ReactNode;
    actions?: ReactNode;
}) {
    return (
        <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div className="space-y-1.5">
                <h1 className="text-3xl font-extrabold tracking-tight md:text-[34px]">
                    {title}
                </h1>
                {description && (
                    <p className="text-[15px] text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            {actions && (
                <div className="flex shrink-0 items-center gap-2">
                    {actions}
                </div>
            )}
        </div>
    );
}
