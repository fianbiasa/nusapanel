/** A quantity a hosting package can limit. */
export type QuotaKey =
    | 'disk_mb'
    | 'inodes'
    | 'bandwidth_mb'
    | 'domains'
    | 'subdomains'
    | 'pointers'
    | 'email_accounts'
    | 'databases'
    | 'ftp_accounts'
    | 'cron_jobs';

/** A package's quantity limits; null means unlimited. */
export type PackageLimits = Record<QuotaKey, number | null>;

/** A hosting package as the admin pages show it. */
export type HostingPackage = {
    id: number;
    name: string;
    description: string | null;
    limits: PackageLimits;
    ssl: boolean;
    accounts_count: number;
};

/** A package to pick for an account. */
export type PackageOption = {
    id: number;
    name: string;
};

/**
 * What the signed-in hosting account's package allows and how much of it
 * the account uses. `used` is null while the panel cannot measure it (yet);
 * `package` is null for accounts without limits. Disk space and inodes are
 * measured by the server: `measured_at` is when they last were, and
 * `quota_enabled` whether the server enforces disk quotas (null until it
 * first reported).
 */
export type AccountLimits = {
    package: string | null;
    ssl: boolean;
    quotas: Record<QuotaKey, { limit: number | null; used: number | null }>;
    measured_at: string | null;
    quota_enabled: boolean | null;
};
