import type { QuotaKey, Role } from '@/types';

/** Display names of the account roles. */
export const roleLabels: Record<Role, string> = {
    admin: 'Administrator',
    reseller: 'Reseller',
    user: 'Pengguna',
};

/** What an audit log entry records, by its action name. */
export const auditActionLabels: Record<string, string> = {
    'account.created': 'Akun dibuat',
    'account.deleted': 'Akun dihapus',
    'account.transferred': 'Akun dipindah ke pemilik lain',
    'account.reseller_package_changed': 'Paket reseller diganti',
    'account.login_as': 'Masuk sebagai akun ini',
    'account.login_as_ended': 'Kembali dari masuk sebagai akun ini',
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
    'file.created': 'File dibuat',
    'file.folder_created': 'Folder dibuat',
    'file.renamed': 'File diganti nama',
    'file.copied': 'File disalin',
    'file.moved': 'File dipindah',
    'file.trashed': 'File dipindah ke Trash',
    'file.restored': 'File dipulihkan dari Trash',
    'file.purged': 'File dihapus permanen',
    'file.permissions_changed': 'Izin file diubah',
    'file.uploaded': 'File diunggah',
    'file.saved': 'File disimpan dari editor',
    'file.compressed': 'Arsip dibuat',
    'service.start': 'Service dinyalakan',
    'service.stop': 'Service dihentikan',
    'service.restart': 'Service di-restart',
    'service.reload': 'Service di-reload',
    'server.rebuilt': 'Konfigurasi server ditulis ulang',
    'domain.www_changed': 'Alamat www diubah',
    'domain.php_enabled': 'PHP dinyalakan',
    'domain.php_disabled': 'PHP dimatikan',
    'domain.root_changed': 'Folder website diganti',
    'server.timezone_changed': 'Zona waktu server diubah',
    'server.nameservers_changed': 'Nameserver DNS diubah',
    'dns.record_created': 'Record DNS ditambah',
    'dns.record_updated': 'Record DNS diubah',
    'dns.record_deleted': 'Record DNS dihapus',
    'dns.zone_reset': 'Zone DNS dikembalikan ke bawaan',
    'dns.template_changed': 'Template DNS diubah',
    'dns.template_reset': 'Template DNS dikembalikan ke bawaan',
    'mail_domain.enabled': 'Email domain diaktifkan',
    'mailbox.created': 'Akun email dibuat',
    'mailbox.updated': 'Akun email diubah',
    'mailbox.deleted': 'Akun email dihapus',
    'mailbox.webmail_opened': 'Webmail dibuka',
    'mail_forwarder.created': 'Forwarder email dibuat',
    'mail_forwarder.updated': 'Forwarder email diubah',
    'mail_forwarder.deleted': 'Forwarder email dihapus',
    'mail_domain.catchall_updated': 'Catch-all diubah',
    'mailbox.autoreply_updated': 'Balasan otomatis diubah',
    'mailbox.filter_created': 'Filter email dibuat',
    'mailbox.filter_deleted': 'Filter email dihapus',
    'server.php_uploads_changed': 'Batas upload PHP diubah',
    'server.hostname_ssl_requested': 'SSL hostname diminta',
    'server.hostname_change_started': 'Penggantian hostname dimulai',
    'server.panel_port_tried': 'Port panel dicoba',
    'server.panel_port_changed': 'Port panel diganti',
    'server.panel_port_cancelled': 'Percobaan port panel dibatalkan',
    'file.extracted': 'Arsip diekstrak',
    'cron_job.created': 'Cron job dibuat',
    'cron_job.updated': 'Cron job diubah',
    'cron_job.deleted': 'Cron job dihapus',
    'cron_job.email_changed': 'Email output cron diubah',
    'account.sftp_password_set': 'Password SFTP diatur',
    'account.sftp_password_removed': 'Password SFTP dihapus',
    'account.ssh_enabled': 'SSH dinyalakan',
    'account.ssh_disabled': 'SSH dimatikan',
    'ssh_key.added': 'SSH key ditambahkan',
    'ssh_key.removed': 'SSH key dihapus',
    'redirect.created': 'Pengalihan ditambahkan',
    'redirect.deleted': 'Pengalihan dihapus',
    'database.remote_host_added': 'Host database luar diizinkan',
    'database.remote_host_removed': 'Host database luar dihapus',
    'database.exported': 'Database diexport',
    'domain.log_downloaded': 'Log website diunduh',
    'server.process_killed': 'Proses dihentikan',
    'database.imported': 'Import database dimulai',
    'database.maintained': 'Tabel database diperiksa',
    'server.database_remote_access_changed': 'Akses database dari luar diubah',
    'domain.limits_changed': 'Batas website diubah',
    'domain.limit_reached': 'Website dihentikan karena batasnya',
    'domain.limit_cleared': 'Website jalan lagi di bawah batasnya',
    'domain.cgi_enabled': 'CGI dinyalakan',
    'domain.cgi_disabled': 'CGI dimatikan',
    'domain.csr_created': 'CSR dibuat',
    'domain.csr_deleted': 'CSR dihapus',
    'domain.error_page_saved': 'Halaman error disimpan',
    'domain.error_page_removed': 'Halaman error dikembalikan ke bawaan',
    'protected_directory.created': 'Folder dilindungi password',
    'protected_directory.deleted': 'Perlindungan folder dicabut',
    'protected_directory.login_added': 'Login folder ditambahkan',
    'protected_directory.login_password_changed':
        'Password login folder diganti',
    'protected_directory.login_removed': 'Login folder dihapus',
    'domain.hotlink_changed': 'Proteksi hotlink diubah',
    'domain.mime_types_changed': 'Tipe MIME diubah',
    'domain.php_extensions_changed': 'Ekstensi PHP diubah',
    'package.created': 'Paket dibuat',
    'package.updated': 'Paket diubah',
    'package.deleted': 'Paket dihapus',
    'reseller_package.created': 'Paket reseller dibuat',
    'reseller_package.updated': 'Paket reseller diubah',
    'reseller_package.deleted': 'Paket reseller dihapus',
    'server.bandwidth_suspend_changed':
        'Suspend otomatis karena bandwidth diubah',
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
    'email_forwarders',
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
    email_forwarders: 'Forwarder email',
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
    'ssl.issue_hostname': 'Pasang SSL hostname',
    'ssl.install': 'Pasang sertifikat sendiri',
    'db.create_database': 'Buat database',
    'db.delete_database': 'Hapus database',
    'db.apply_user': 'Atur user database',
    'db.delete_user': 'Hapus user database',
    'ftp.ensure_dir': 'Siapkan folder FTP',
    'cron.apply': 'Terapkan cron job',
    'account.set_password': 'Atur password SFTP',
    'account.set_ssh': 'Atur akses SSH',
    'account.ssh_keys': 'Tulis SSH key',
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
