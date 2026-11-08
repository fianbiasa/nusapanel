import { Head } from '@inertiajs/react';
import { PackageForm } from '@/components/package-form';
import { PageHeader } from '@/components/page-header';
import type { HostingPackage } from '@/types';

export default function PackagesEdit({
    package: pkg,
}: {
    package: HostingPackage;
}) {
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

            <PackageForm pkg={pkg} />
        </>
    );
}
