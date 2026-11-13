/** A quantity a hosting package can limit. */
export type QuotaKey =
    | 'disk_mb'
    | 'inodes'
    | 'bandwidth_mb'
    | 'domains'
    | 'subdomains'
    | 'pointers'
    | 'email_accounts'
    | 'email_forwarders'
    | 'databases'
    | 'ftp_accounts'
    | 'cron_jobs';

/** A package's quantity limits; null means unlimited. */
export type PackageLimits = Record<QuotaKey, number | null>;

/** What each account on a hosting package may use of the server at once. */
export type ResourceKey =
    | 'cpu_percent'
    | 'memory_mb'
    | 'max_processes'
    | 'io_weight';

/** A package's resource limits; null means unlimited (or for io_weight the server's default). */
export type ResourceLimits = Record<ResourceKey, number | null>;

/** A hosting package as the admin pages show it. */
export type HostingPackage = {
    id: number;
    name: string;
    description: string | null;
    limits: PackageLimits;
    resources: ResourceLimits;
    ssl: boolean;
    cgi: boolean;
    ssh: boolean;
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
    cgi: boolean;
    ssh: boolean;
    quotas: Record<QuotaKey, { limit: number | null; used: number | null }>;
    measured_at: string | null;
    quota_enabled: boolean | null;
};

/** A quantity a reseller package limits: the number of accounts, then those of a hosting package. */
export type ResellerQuotaKey = 'accounts' | QuotaKey;

/** A reseller package as the admin pages show it. */
export type ResellerPackage = {
    id: number;
    name: string;
    description: string | null;
    limits: Record<ResellerQuotaKey, number | null>;
    ssl: boolean;
    cgi: boolean;
    ssh: boolean;
    oversell: boolean;
    resellers_count: number;
};

/**
 * For a reseller: the limits of its reseller package, which its own
 * packages may not go over; `ssl`, `cgi` and `ssh` are 1 when it may give them.
 */
export type ResellerPackageLimits = Record<QuotaKey, number | null> & {
    ssl: number;
    cgi: number;
    ssh: number;
};

/**
 * What a reseller's package allows, how much its accounts use together
 * (`used`, null while not measured) and how much their packages give out
 * (`allocated`, null when a package does not limit it).
 */
export type ResellerLimits = {
    package: string | null;
    oversell: boolean;
    ssl: boolean;
    cgi: boolean;
    ssh: boolean;
    quotas: Record<
        ResellerQuotaKey,
        {
            limit: number | null;
            used: number | null;
            allocated: number | null;
        }
    >;
};
