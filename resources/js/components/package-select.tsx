import { useState } from 'react';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { PackageOption } from '@/types';

/** The select's value for "no package"; Radix items cannot be empty. */
const noPackage = 'none';

/**
 * Picks the package of an account, or none (no limits), for a `<Form>`:
 * the choice is sent as `name` (`package_id` unless given), empty for no
 * package.
 */
export function PackageSelect({
    id,
    name = 'package_id',
    packages,
    defaultValue = null,
    invalid = false,
}: {
    id: string;
    name?: string;
    packages: PackageOption[];
    defaultValue?: number | null;
    invalid?: boolean;
}) {
    const [value, setValue] = useState(
        defaultValue === null ? noPackage : String(defaultValue),
    );

    return (
        <>
            <input
                type="hidden"
                name={name}
                value={value === noPackage ? '' : value}
            />
            <Select value={value} onValueChange={setValue}>
                <SelectTrigger
                    id={id}
                    aria-invalid={invalid || undefined}
                    className="w-full rounded-xl bg-card px-3.5 data-[size=default]:h-11 md:max-w-sm"
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent className="rounded-xl">
                    <SelectItem value={noPackage}>
                        Tanpa paket (tanpa batas)
                    </SelectItem>
                    {packages.map((option) => (
                        <SelectItem key={option.id} value={String(option.id)}>
                            {option.name}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </>
    );
}
