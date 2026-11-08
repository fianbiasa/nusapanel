/** A quantity a hosting package can limit. */
export type QuotaKey =
    | 'disk_mb'
    | 'bandwidth_mb'
    | 'domains'
    | 'subdomains'
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
 * the account uses. `used` is null while the panel cannot measure it yet;
 * `package` is null for accounts without limits.
 */
export type AccountLimits = {
    package: string | null;
    ssl: boolean;
    quotas: Record<QuotaKey, { limit: number | null; used: number | null }>;
};
