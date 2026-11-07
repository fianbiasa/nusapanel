import { cn } from '@/lib/utils';

/**
 * The nusapanel cube mark with the "Nusapanel" wordmark. The wordmark is
 * text, not an image, so it stays sharp and follows light and dark mode.
 */
export default function AppLogo({
    className,
    showWordmark = true,
    size = 'md',
}: {
    className?: string;
    showWordmark?: boolean;
    size?: 'md' | 'lg';
}) {
    return (
        <span className={cn('flex items-center gap-2.5', className)}>
            <AppLogoMark
                className={size === 'lg' ? 'size-12' : 'size-9 shrink-0'}
            />
            {showWordmark && (
                <span
                    className={cn(
                        'truncate font-extrabold tracking-tight',
                        size === 'lg' ? 'text-3xl' : 'text-[22px]',
                    )}
                >
                    Nusa<span className="text-primary">panel</span>
                </span>
            )}
        </span>
    );
}

export function AppLogoMark({ className }: { className?: string }) {
    return (
        <img
            src="/images/nusapanel-mark.png"
            alt=""
            aria-hidden="true"
            className={cn('select-none', className)}
            draggable={false}
        />
    );
}
