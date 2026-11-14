import * as adminAccounts from '@/routes/admin/accounts';
import * as adminBackups from '@/routes/admin/backups';
import { update as adminAccountAddresses } from '@/routes/admin/accounts/addresses';
import { update as adminAccountPackage } from '@/routes/admin/accounts/package';
import * as adminPackages from '@/routes/admin/packages';
import * as resellerAccounts from '@/routes/reseller/accounts';
import * as resellerBackups from '@/routes/reseller/backups';
import { update as resellerAccountAddresses } from '@/routes/reseller/accounts/addresses';
import { update as resellerAccountPackage } from '@/routes/reseller/accounts/package';
import * as resellerPackages from '@/routes/reseller/packages';

/**
 * Whose account list and packages a page shows: the administrators'
 * (every account, the administrators' packages) or a reseller's (the
 * hosting accounts and packages it owns). Both areas share the pages and
 * have routes of the same shape.
 */
export type AccountScope = 'admin' | 'reseller';

/** The account list routes of the area. */
export function accountRoutes(scope: AccountScope) {
    return scope === 'admin'
        ? {
              ...adminAccounts,
              updatePackage: adminAccountPackage,
              updateAddresses: adminAccountAddresses,
          }
        : {
              ...resellerAccounts,
              updatePackage: resellerAccountPackage,
              updateAddresses: resellerAccountAddresses,
          };
}

/** The package routes of the area. */
export function packageRoutes(scope: AccountScope) {
    return scope === 'admin' ? adminPackages : resellerPackages;
}

/** The routes of the backups the server keeps, in the area. */
export function backupRoutes(scope: AccountScope) {
    return scope === 'admin' ? adminBackups : resellerBackups;
}
