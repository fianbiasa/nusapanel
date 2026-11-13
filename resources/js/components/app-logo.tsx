import { usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';

/**
 * The RAYXO mark with the "RAYXO" wordmark, whose X carries the logo's
 * gradient, or a reseller's own logo and name. The wordmark is text, not
 * an image, so it stays sharp and follows light and dark mode.
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
    const { branding } = usePage().props;

    return (
        <span className={cn('flex items-center gap-2.5', className)}>
            <AppLogoMark
                className={size === 'lg' ? 'size-12' : 'size-9 shrink-0'}
            />
            {showWordmark &&
                (branding ? (
                    branding.name && (
                        <span
                            className={cn(
                                'truncate font-extrabold tracking-tight',
                                size === 'lg' ? 'text-2xl' : 'text-lg',
                            )}
                        >
                            {branding.name}
                        </span>
                    )
                ) : (
                    <span
                        className={cn(
                            'truncate font-black tracking-tight',
                            size === 'lg' ? 'text-3xl' : 'text-[22px]',
                        )}
                    >
                        RAY
                        <span className="rayxo-logo-gradient bg-clip-text text-transparent">
                            X
                        </span>
                        O
                    </span>
                ))}
        </span>
    );
}

/**
 * The RAYXO mark, or a reseller's logo; a reseller without one gets the
 * first letter of its name on its colour.
 */
export function AppLogoMark({ className }: { className?: string }) {
    const { branding } = usePage().props;

    if (branding?.logo_url) {
        return (
            <img
                src={branding.logo_url}
                alt=""
                aria-hidden="true"
                className={cn('object-contain select-none', className)}
                draggable={false}
            />
        );
    }

    if (branding) {
        return (
            <span
                aria-hidden="true"
                className={cn(
                    'flex items-center justify-center rounded-xl bg-primary font-extrabold text-primary-foreground select-none',
                    className,
                )}
            >
                {(branding.name ?? '?').slice(0, 1).toUpperCase()}
            </span>
        );
    }

    return (
        <img
            src="/images/rayxo-mark.png"
            alt=""
            aria-hidden="true"
            className={cn('select-none', className)}
            draggable={false}
        />
    );
}
