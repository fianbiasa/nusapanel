import type { Role } from '@/types';

/** Display names of the account roles. */
export const roleLabels: Record<Role, string> = {
    admin: 'Administrator',
    reseller: 'Reseller',
    user: 'Pengguna',
};

/** What an audit log entry records, by its action name. */
export const auditActionLabels: Record<string, string> = {
    'account.created': 'Akun hosting dibuat',
    'account.deleted': 'Akun hosting dihapus',
    'account.suspended': 'Akun disuspend',
    'account.unsuspended': 'Suspend akun dibuka',
    'account.password_reset': 'Kata sandi direset',
    'auth.login': 'Masuk ke panel',
    'domain.created': 'Domain ditambahkan',
    'domain.deleted': 'Domain dihapus',
    'domain.ssl_requested': 'SSL diminta',
    'domain.ssl_removed': 'SSL dilepas',
    'domain.ssl_cancelled': 'Permintaan SSL dibatalkan',
    'domain.https_forced': 'Paksa HTTPS diaktifkan',
    'domain.https_unforced': 'Paksa HTTPS dimatikan',
};

/** What a task asks the server agent to do, by its agent method. */
export const taskActionLabels: Record<string, string> = {
    'account.create': 'Buat akun di server',
    'account.suspend': 'Suspend akun',
    'account.unsuspend': 'Buka suspend akun',
    'account.delete': 'Hapus akun dari server',
    'web.apply_domain': 'Pasang website domain',
    'web.remove_domain': 'Lepas website domain',
    'php.remove_pool': 'Hapus pool PHP',
    'ssl.issue': 'Pasang sertifikat SSL',
    'ssl.delete': 'Hapus sertifikat SSL',
    'system.info': 'Baca info server',
};

/** "5 Feb 2027" */
export function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString('id-ID', { dateStyle: 'medium' });
}

export function formatDateTime(iso: string): string {
    return new Date(iso).toLocaleString('id-ID', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

/** "2 menit yang lalu", "kemarin", ... for times in the past. */
export function formatRelative(iso: string): string {
    const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000);
    const format = new Intl.RelativeTimeFormat('id-ID', { numeric: 'auto' });
    const units: [Intl.RelativeTimeFormatUnit, number][] = [
        ['day', 86_400],
        ['hour', 3_600],
        ['minute', 60],
    ];

    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) {
            return format.format(Math.round(seconds / size), unit);
        }
    }

    return 'baru saja';
}

export function formatUptime(seconds: number): string {
    const days = Math.floor(seconds / 86_400);
    const hours = Math.floor((seconds % 86_400) / 3_600);
    const minutes = Math.floor((seconds % 3_600) / 60);

    if (days > 0) {
        return `${days} hari ${hours} jam`;
    }

    return hours > 0 ? `${hours} jam ${minutes} menit` : `${minutes} menit`;
}

export function percentage(used: number, total: number): number {
    return total > 0 ? Math.round((used / total) * 100) : 0;
}
