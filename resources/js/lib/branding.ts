import { router } from '@inertiajs/react';

/**
 * A reseller's own look of the panel for itself and its accounts; null
 * fields keep RAYXO's.
 */
export type Branding = {
    name: string | null;
    /** "#rrggbb". */
    color: string | null;
    logo_url: string | null;
};

let brandName: string | null = null;

/**
 * The name page titles end with: the reseller's brand, else the panel's.
 * Page titles are set outside React, so the brand of the current page is
 * kept here as pages change.
 */
export function titleName(fallback: string): string {
    return brandName ?? fallback;
}

/**
 * Keep the brand of the page being shown for its title; the layouts call
 * it as they render, before the page's title is set.
 */
export function rememberBrandName(name: string | null): void {
    brandName = name;
}

if (typeof window !== 'undefined') {
    router.on('navigate', (event) => {
        const branding = event.detail.page.props.branding as
            | Branding
            | null
            | undefined;

        rememberBrandName(branding?.name ?? null);
    });
}

/**
 * The text colour that reads on the brand colour: dark on light colours,
 * white on dark ones (WCAG relative luminance).
 */
export function foregroundOn(color: string): string {
    const channels = [1, 3, 5].map((start) => {
        const value = parseInt(color.slice(start, start + 2), 16) / 255;

        return value <= 0.03928
            ? value / 12.92
            : ((value + 0.055) / 1.055) ** 2.4;
    });
    const luminance =
        0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];

    return luminance > 0.4 ? '#101b39' : '#ffffff';
}
