/**
 * A bold title with a muted line of explanation. The default variant heads a
 * page; the small one heads a card, like the panel titles on the dashboard.
 */
export default function Heading({
    title,
    description,
    variant = 'default',
}: {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
}) {
    if (variant === 'small') {
        return (
            <header className="min-w-0 space-y-1">
                <h2 className="text-lg font-bold tracking-tight">{title}</h2>
                {description && (
                    <p className="text-sm text-muted-foreground">
                        {description}
                    </p>
                )}
            </header>
        );
    }

    return (
        <header className="mb-8 space-y-1.5">
            <h2 className="text-2xl font-extrabold tracking-tight">{title}</h2>
            {description && (
                <p className="text-[15px] text-muted-foreground">
                    {description}
                </p>
            )}
        </header>
    );
}
