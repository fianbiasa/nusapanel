import { Head } from '@inertiajs/react';
import { PackageForm } from '@/components/package-form';
import { PageHeader } from '@/components/page-header';

export default function PackagesCreate() {
    return (
        <>
            <Head title="Buat paket" />

            <PageHeader
                title="Buat paket"
                description="Paket menentukan batas akun hosting: kuota, jumlah domain, dan fitur yang boleh dipakai."
            />

            <PackageForm />
        </>
    );
}
