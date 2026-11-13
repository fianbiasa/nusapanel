import type { Branding } from '@/lib/branding';
import type { Auth } from '@/types/auth';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            /** A reseller's look of the panel, or null for RAYXO's. */
            branding: Branding | null;
            [key: string]: unknown;
        };
    }
}
