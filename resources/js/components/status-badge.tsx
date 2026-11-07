import { cn } from '@/lib/utils';

type Tone = 'success' | 'warning' | 'danger' | 'info' | 'neutral';

/**
 * Every status the panel shows (accounts, domains, tasks), with its label
 * and color. Busy statuses pulse, so a page that refreshes itself shows it
 * is still working.
 */
const statuses: Record<string, { label: string; tone: Tone; busy?: boolean }> =
    {
        active: { label: 'Aktif', tone: 'success' },
        success: { label: 'Berhasil', tone: 'success' },
        pending: { label: 'Disiapkan', tone: 'info', busy: true },
        queued: { label: 'Antre', tone: 'info', busy: true },
        running: { label: 'Berjalan', tone: 'info', busy: true },
        deleting: { label: 'Dihapus', tone: 'warning', busy: true },
        suspended: { label: 'Suspend', tone: 'warning' },
        failed: { label: 'Gagal', tone: 'danger' },
        cancelled: { label: 'Dibatalkan', tone: 'neutral' },
    };

const tones: Record<Tone, string> = {
    success: 'bg-success/10 text-success-foreground ring-success/20',
    warning: 'bg-warning/12 text-warning-foreground ring-warning/25',
    danger: 'bg-destructive/10 text-destructive-foreground ring-destructive/20',
    info: 'bg-primary/10 text-primary ring-primary/20',
    neutral: 'bg-muted text-muted-foreground ring-border',
};

const dots: Record<Tone, string> = {
    success: 'bg-success',
    warning: 'bg-warning',
    danger: 'bg-destructive',
    info: 'bg-primary',
    neutral: 'bg-muted-foreground',
};

export function StatusBadge({
    status,
    className,
}: {
    status: string;
    className?: string;
}) {
    const { label, tone, busy } = statuses[status] ?? {
        label: status,
        tone: 'neutral' as Tone,
    };

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset',
                tones[tone],
                className,
            )}
        >
            <span
                className={cn(
                    'size-1.5 rounded-full',
                    dots[tone],
                    busy && 'animate-pulse',
                )}
            />
            {label}
        </span>
    );
}
