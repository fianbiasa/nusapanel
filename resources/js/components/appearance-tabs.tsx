import type { LucideIcon } from 'lucide-react';
import { Check, Monitor, Moon, Sun } from 'lucide-react';
import type { HTMLAttributes } from 'react';
import type { Appearance } from '@/hooks/use-appearance';
import { useAppearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';

const options: {
    value: Appearance;
    icon: LucideIcon;
    label: string;
    description: string;
}[] = [
    {
        value: 'light',
        icon: Sun,
        label: 'Terang',
        description: 'Latar cerah, nyaman di siang hari.',
    },
    {
        value: 'dark',
        icon: Moon,
        label: 'Gelap',
        description: 'Lebih nyaman di ruangan redup.',
    },
    {
        value: 'system',
        icon: Monitor,
        label: 'Sistem',
        description: 'Mengikuti pengaturan perangkat kamu.',
    },
];

/**
 * The theme picker: one card per theme, each with a small preview of the
 * panel in that theme.
 */
export default function AppearanceToggleTab({
    className = '',
    ...props
}: HTMLAttributes<HTMLDivElement>) {
    const { appearance, updateAppearance } = useAppearance();

    return (
        <div className={cn('@container', className)} {...props}>
            <div className="grid gap-3 @lg:grid-cols-3 @lg:gap-4">
                {options.map(({ value, icon: Icon, label, description }) => {
                    const isSelected = appearance === value;

                    return (
                        <button
                            key={value}
                            type="button"
                            onClick={() => updateAppearance(value)}
                            aria-pressed={isSelected}
                            className={cn(
                                'flex items-center gap-4 rounded-2xl border bg-card p-3 text-left transition-[border-color,box-shadow] outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 @lg:flex-col @lg:items-stretch @lg:gap-3',
                                isSelected
                                    ? 'border-primary ring-[3px] ring-primary/15'
                                    : 'hover:border-primary/40 hover:shadow-card',
                            )}
                        >
                            <ThemePreview
                                theme={value}
                                className="w-28 shrink-0 @lg:w-full"
                            />

                            <span className="flex min-w-0 flex-1 items-start gap-2.5 @lg:px-1 @lg:pb-1">
                                <Icon
                                    className={cn(
                                        'mt-0.5 size-4 shrink-0 transition-colors',
                                        isSelected
                                            ? 'text-primary'
                                            : 'text-muted-foreground',
                                    )}
                                />
                                <span className="min-w-0 flex-1">
                                    <span className="block text-sm font-semibold">
                                        {label}
                                    </span>
                                    <span className="block text-xs text-muted-foreground">
                                        {description}
                                    </span>
                                </span>
                                <span
                                    className={cn(
                                        'flex size-5 shrink-0 items-center justify-center rounded-full border transition-colors',
                                        isSelected
                                            ? 'border-primary bg-primary text-primary-foreground'
                                            : 'border-input',
                                    )}
                                >
                                    {isSelected && (
                                        <Check
                                            className="size-3"
                                            strokeWidth={3}
                                        />
                                    )}
                                </span>
                            </span>
                        </button>
                    );
                })}
            </div>
        </div>
    );
}

const palettes = {
    light: {
        page: 'bg-[#f4f7fd]',
        sidebar: 'bg-white',
        card: 'bg-white',
        line: 'bg-[#e2eaf7]',
        text: 'bg-[#101b39]/70',
        brand: 'bg-[#086cf0]',
    },
    dark: {
        page: 'bg-[#070f24]',
        sidebar: 'bg-[#0b1530]',
        card: 'bg-[#0e1a37]',
        line: 'bg-[#1d2c55]',
        text: 'bg-[#e8eefc]/70',
        brand: 'bg-[#1688ff]',
    },
};

/**
 * A miniature of the panel (sidebar, title, cards) in fixed light or dark
 * colors, so each option shows its theme whatever the current one is. The
 * system option shows both, split diagonally.
 */
function ThemePreview({
    theme,
    className,
}: {
    theme: Appearance;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'relative block aspect-[16/10] overflow-hidden rounded-xl border',
                className,
            )}
        >
            {theme === 'system' ? (
                <>
                    <PanelMiniature palette={palettes.light} />
                    <span className="absolute inset-0 [clip-path:polygon(100%_0,100%_100%,0_100%)]">
                        <PanelMiniature palette={palettes.dark} />
                    </span>
                </>
            ) : (
                <PanelMiniature palette={palettes[theme]} />
            )}
        </span>
    );
}

function PanelMiniature({ palette }: { palette: (typeof palettes)['light'] }) {
    return (
        <span
            className={cn('flex size-full gap-2 p-2', palette.page)}
            aria-hidden="true"
        >
            <span
                className={cn(
                    'flex w-1/4 flex-col gap-1.5 rounded-md p-1.5',
                    palette.sidebar,
                )}
            >
                <span className={cn('h-2 rounded-sm', palette.brand)} />
                <span className={cn('h-1.5 rounded-sm', palette.line)} />
                <span className={cn('h-1.5 rounded-sm', palette.line)} />
                <span className={cn('h-1.5 rounded-sm', palette.line)} />
            </span>
            <span className="flex flex-1 flex-col gap-1.5">
                <span className={cn('h-2 w-1/2 rounded-sm', palette.text)} />
                <span className="grid flex-1 grid-cols-2 gap-1.5">
                    <span
                        className={cn(
                            'flex flex-col justify-end gap-1 rounded-md p-1.5',
                            palette.card,
                        )}
                    >
                        <span
                            className={cn(
                                'h-1.5 w-2/3 rounded-sm',
                                palette.brand,
                            )}
                        />
                        <span className={cn('h-1 rounded-sm', palette.line)} />
                    </span>
                    <span
                        className={cn(
                            'flex flex-col justify-end gap-1 rounded-md p-1.5',
                            palette.card,
                        )}
                    >
                        <span
                            className={cn(
                                'h-1.5 w-1/2 rounded-sm',
                                palette.text,
                            )}
                        />
                        <span className={cn('h-1 rounded-sm', palette.line)} />
                    </span>
                    <span
                        className={cn(
                            'col-span-2 flex items-end gap-1 rounded-md p-1.5',
                            palette.card,
                        )}
                    >
                        <span
                            className={cn(
                                'h-2/5 flex-1 rounded-sm',
                                palette.line,
                            )}
                        />
                        <span
                            className={cn(
                                'h-3/5 flex-1 rounded-sm',
                                palette.line,
                            )}
                        />
                        <span
                            className={cn(
                                'h-4/5 flex-1 rounded-sm',
                                palette.brand,
                            )}
                        />
                        <span
                            className={cn(
                                'h-1/2 flex-1 rounded-sm',
                                palette.line,
                            )}
                        />
                    </span>
                </span>
            </span>
        </span>
    );
}
