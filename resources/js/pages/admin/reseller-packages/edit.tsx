import { Head } from '@inertiajs/react';
import { PackageForm } from '@/components/package-form';
import { PageHeader } from '@/components/page-header';
import { index, update } from '@/routes/admin/reseller-packages';
import type { ResellerPackage } from '@/types';

export default function ResellerPackagesEdit({
    package: pkg,
}: {
    package: ResellerPackage;
}) {
    return (
        <>
            <Head title={`Ubah paket reseller ${pkg.name}`} />

            <PageHeader
                title={`Ubah paket reseller ${pkg.name}`}
                description={
                    pkg.resellers_count > 0
                        ? `Dipakai ${pkg.resellers_count} reseller. Perubahan langsung berlaku; reseller yang sudah membagikan lebih dari batas barunya tidak bisa menambah lagi.`
                        : 'Belum ada reseller yang memakai paket ini.'
                }
            />

            <PackageForm
                kind="reseller"
                pkg={pkg}
                form={update.form(pkg.id)}
                cancelHref={index()}
            />
        </>
    );
}
