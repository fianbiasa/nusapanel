import type { QuotaKey, Role } from '@/types';

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
    'account.package_changed': 'Paket akun diganti',
    'auth.login': 'Masuk ke panel',
    'domain.created': 'Domain ditambahkan',
    'domain.deleted': 'Domain dihapus',
    'domain.ssl_requested': 'SSL diminta',
    'domain.ssl_removed': 'SSL dilepas',
    'domain.ssl_cancelled': 'Permintaan SSL dibatalkan',
    'domain.ssl_custom_installed': 'Sertifikat sendiri dipasang',
    'domain.https_forced': 'Paksa HTTPS diaktifkan',
    'domain.https_unforced': 'Paksa HTTPS dimatikan',
    'domain.php_changed': 'Versi PHP diganti',
    'domain.php_settings_changed': 'Pengaturan PHP diubah',
    'subdomain.created': 'Subdomain ditambahkan',
    'subdomain.deleted': 'Subdomain dihapus',
    'pointer.created': 'Pointer ditambahkan',
    'pointer.deleted': 'Pointer dihapus',
    'database.created': 'Database dibuat',
    'database.deleted': 'Database dihapus',
    'database.phpmyadmin_opened': 'phpMyAdmin dibuka',
    'database_user.created': 'User database dibuat',
    'database_user.updated': 'User database diubah',
    'database_user.deleted': 'User database dihapus',
    'ftp_account.created': 'Akun FTP dibuat',
    'ftp_account.updated': 'Akun FTP diubah',
    'ftp_account.deleted': 'Akun FTP dihapus',
    'redirect.created': 'Pengalihan ditambahkan',
    'redirect.deleted': 'Pengalihan dihapus',
    'package.created': 'Paket dibuat',
    'package.updated': 'Paket diubah',
    'package.deleted': 'Paket dihapus',
};

/** The quantities a package limits, in the order the panel shows them. */
export const quotaKeys: QuotaKey[] = [
    'disk_mb',
    'inodes',
    'bandwidth_mb',
    'domains',
    'subdomains',
    'pointers',
    'email_accounts',
    'databases',
    'ftp_accounts',
    'cron_jobs',
];

/** Display names of the quantities a package limits. */
export const quotaLabels: Record<QuotaKey, string> = {
    disk_mb: 'Disk',
    inodes: 'Inode',
    bandwidth_mb: 'Bandwidth',
    domains: 'Domain',
    subdomains: 'Subdomain',
    pointers: 'Pointer',
    email_accounts: 'Akun email',
    databases: 'Database',
    ftp_accounts: 'Akun FTP',
    cron_jobs: 'Cron job',
};

/** "500 MB", "1 GB", "1,5 TB" */
export function formatMegabytes(megabytes: number): string {
    const units = ['MB', 'GB', 'TB'];
    let value = megabytes;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${value.toLocaleString('id-ID', { maximumFractionDigits: 1 })} ${units[unit]}`;
}

/** "0 B", "512 KB", "12,3 MB", "1,2 GB" */
export function formatBytes(bytes: number): string {
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let value = bytes;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${value.toLocaleString('id-ID', { maximumFractionDigits: unit < 2 ? 0 : 1 })} ${units[unit]}`;
}

/** Disk usage against its limit: "120 MB / 1 GB", "120 MB" or "—". */
export function formatDiskUsage(
    usedMb: number | null,
    limitMb: number | null,
): string {
    if (usedMb === null) {
        return '—';
    }

    return limitMb === null
        ? formatMegabytes(usedMb)
        : `${formatMegabytes(usedMb)} / ${formatMegabytes(limitMb)}`;
}

/** A package limit as people read it: "1 GB", "5" or "Tanpa batas". */
export function formatQuota(key: QuotaKey, value: number | null): string {
    if (value === null) {
        return 'Tanpa batas';
    }

    return key === 'disk_mb' || key === 'bandwidth_mb'
        ? formatMegabytes(value)
        : value.toLocaleString('id-ID');
}

/** What a task asks the server agent to do, by its agent method. */
export const taskActionLabels: Record<string, string> = {
    'account.create': 'Buat akun di server',
    'account.suspend': 'Suspend akun',
    'account.unsuspend': 'Buka suspend akun',
    'account.delete': 'Hapus akun dari server',
    'account.set_quota': 'Atur kuota disk',
    'web.apply_domain': 'Pasang website domain',
    'web.remove_domain': 'Lepas website domain',
    'php.remove_pool': 'Hapus pool PHP',
    'ssl.issue': 'Pasang sertifikat SSL',
    'ssl.install': 'Pasang sertifikat sendiri',
    'db.create_database': 'Buat database',
    'db.delete_database': 'Hapus database',
    'db.apply_user': 'Atur user database',
    'db.delete_user': 'Hapus user database',
    'ftp.ensure_dir': 'Siapkan folder FTP',
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
