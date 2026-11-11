import { Globe } from 'lucide-react';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';

export type DomainSwitcherOption = {
    value: string;
    label: string;
    /** Shown in the page's own font instead of monospace, e.g. "Semua". */
    plain?: boolean;
};

/**
 * Up to this many domains are chips; more become a dropdown.
 */
const MAX_CHIPS = 6;

/**
 * Which domain a page shows, at the top of the page where it is easy to
 * see: a row of chips with the chosen one highlighted, or, for accounts
 * with many domains, a labelled dropdown.
 */
export function DomainSwitcher({
    options,
    value,
    onChange,
    label = 'Domain',
}: {
    options: DomainSwitcherOption[];
    value: string;
    onChange: (value: string) => void;
    label?: string;
}) {
    if (options.length > MAX_CHIPS) {
        return (
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-3">
                <Label
                    htmlFor="domain-switcher"
                    className="flex items-center gap-2 text-muted-foreground"
                >
                    <Globe className="size-4" /> {label}
                </Label>
                <Select value={value} onValueChange={onChange}>
                    <SelectTrigger
                        id="domain-switcher"
                        className="w-full rounded-xl bg-card px-3.5 font-mono data-[size=default]:h-11 sm:w-auto sm:min-w-72"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent className="rounded-xl">
                        {options.map((option) => (
                            <SelectItem
                                key={option.value}
                                value={option.value}
                                className={cn(!option.plain && 'font-mono')}
                            >
                                {option.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>
        );
    }

    return (
        <div
            role="group"
            aria-label={label}
            className="flex flex-wrap items-center gap-2"
        >
            <span className="mr-1 flex items-center gap-2 text-sm font-semibold text-muted-foreground">
                <Globe className="size-4" /> {label}
            </span>
            {options.map((option) => {
                const isSelected = option.value === value;

                return (
                    <button
                        key={option.value}
                        type="button"
                        aria-pressed={isSelected}
                        onClick={() => onChange(option.value)}
                        className={cn(
                            'max-w-full rounded-full border px-3.5 py-1.5 text-sm font-semibold break-all transition-[background-color,border-color,color,box-shadow] outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                            !option.plain && 'font-mono',
                            isSelected
                                ? 'border-primary bg-primary text-primary-foreground shadow-xs'
                                : 'bg-card text-foreground hover:border-primary/40 hover:bg-accent',
                        )}
                    >
                        {option.label}
                    </button>
                );
            })}
        </div>
    );
}
