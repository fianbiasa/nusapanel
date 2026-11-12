import { Head } from '@inertiajs/react';
import { PackageForm } from '@/components/package-form';
import { PageHeader } from '@/components/page-header';
import { packageRoutes } from '@/lib/scoped-routes';
import type { AccountScope } from '@/lib/scoped-routes';
import type { HostingPackage, ResellerPackageLimits } from '@/types';

export default function PackagesEdit({
    scope,
    package: pkg,
    resellerLimits,
}: {
    scope: AccountScope;
    package: HostingPackage;
    resellerLimits: ResellerPackageLimits | null;
}) {
    const routes = packageRoutes(scope);

    return (
        <>
            <Head title={`Ubah paket ${pkg.name}`} />

            <PageHeader
                title={`Ubah paket ${pkg.name}`}
                description={
                    pkg.accounts_count > 0
                        ? `Dipakai ${pkg.accounts_count} akun. Perubahan langsung berlaku untuk akun-akun itu.`
                        : 'Belum ada akun yang memakai paket ini.'
                }
            />

            <PackageForm
                pkg={pkg}
                form={routes.update.form(pkg.id)}
                cancelHref={routes.index()}
                resellerLimits={resellerLimits}
            />
        </>
    );
}
