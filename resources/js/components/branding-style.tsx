import { usePage } from '@inertiajs/react';
import { foregroundOn, rememberBrandName } from '@/lib/branding';

const colorPattern = /^#[0-9a-f]{6}$/;

/**
 * Gives the panel the reseller's brand colour, in light and dark mode
 * alike, while it has one.
 */
export function BrandingStyle() {
    const { branding } = usePage().props;
    const color = branding?.color;

    rememberBrandName(branding?.name ?? null);

    if (!color || !colorPattern.test(color)) {
        return null;
    }

    const foreground = foregroundOn(color);

    return (
        <style>{`:root, .dark { --primary: ${color}; --primary-foreground: ${foreground}; --ring: ${color}; --sidebar-primary: ${color}; --sidebar-primary-foreground: ${foreground}; }`}</style>
    );
}
