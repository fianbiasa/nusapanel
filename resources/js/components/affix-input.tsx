import type { ComponentProps, ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * A monospace text input with fixed text in front of it, such as "budi_"
 * or "/home/budi/", and optionally after it, such as "@".
 */
export function AffixInput({
    prefix,
    suffix,
    invalid = false,
    className,
    ...props
}: ComponentProps<'input'> & {
    prefix?: ReactNode;
    suffix?: ReactNode;
    invalid?: boolean;
}) {
    return (
        <div className="flex h-11 min-w-0 items-stretch overflow-hidden rounded-xl border border-input bg-card shadow-xs transition-[color,box-shadow] focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50 has-aria-invalid:border-destructive">
            {prefix !== undefined && (
                <span className="flex max-w-[50%] shrink-0 items-center truncate border-r bg-muted px-3 font-mono text-sm text-muted-foreground select-none">
                    {prefix}
                </span>
            )}
            <input
                autoComplete="off"
                autoCapitalize="none"
                spellCheck={false}
                {...props}
                aria-invalid={invalid || undefined}
                className={cn(
                    'min-w-0 flex-1 bg-transparent px-3 font-mono text-sm outline-none placeholder:text-muted-foreground disabled:cursor-not-allowed disabled:opacity-50',
                    className,
                )}
            />
            {suffix !== undefined && (
                <span className="flex shrink-0 items-center border-l bg-muted px-3 font-mono text-sm text-muted-foreground select-none">
                    {suffix}
                </span>
            )}
        </div>
    );
}
