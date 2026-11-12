import { Head } from '@inertiajs/react';
import { PackageForm } from '@/components/package-form';
import { PageHeader } from '@/components/page-header';
import { packageRoutes } from '@/lib/scoped-routes';
import type { AccountScope } from '@/lib/scoped-routes';
import type { ResellerPackageLimits } from '@/types';

export default function PackagesCreate({
    scope,
    resellerLimits,
}: {
    scope: AccountScope;
    resellerLimits: ResellerPackageLimits | null;
}) {
    const routes = packageRoutes(scope);

    return (
        <>
            <Head title="Buat paket" />

            <PageHeader
                title="Buat paket"
                description="Paket menentukan batas akun hosting: kuota, jumlah domain, dan fitur yang boleh dipakai."
            />

            <PackageForm
                form={routes.store.form()}
                cancelHref={routes.index()}
                resellerLimits={resellerLimits}
            />
        </>
    );
}
