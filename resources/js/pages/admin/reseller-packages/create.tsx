import { Head } from '@inertiajs/react';
import { PackageForm } from '@/components/package-form';
import { PageHeader } from '@/components/page-header';
import { index, store } from '@/routes/admin/reseller-packages';

export default function ResellerPackagesCreate() {
    return (
        <>
            <Head title="Buat paket reseller" />

            <PageHeader
                title="Buat paket reseller"
                description="Paket reseller menentukan berapa akun yang boleh dibuat reseller dan berapa kuota yang boleh dibagikan ke semua akunnya."
            />

            <PackageForm
                kind="reseller"
                form={store.form()}
                cancelHref={index()}
            />
        </>
    );
}
