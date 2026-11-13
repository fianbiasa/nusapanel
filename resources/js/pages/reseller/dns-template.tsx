import { Form, Head } from '@inertiajs/react';
import { Copy } from 'lucide-react';
import type { DnsRecordRow } from '@/components/dns-records';
import { DnsTemplatePanel } from '@/components/dns-template-panel';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import {
    copy,
    destroy,
    reset,
    store,
    update,
} from '@/routes/reseller/dns-template';

/**
 * The records the DNS zones of a reseller's accounts start with: its own
 * template, or the administrators' while it has none.
 */
export default function ResellerDnsTemplate({
    own,
    template,
    nameservers,
    types,
}: {
    own: boolean;
    template: DnsRecordRow[];
    nameservers: string[];
    types: string[];
}) {
    return (
        <>
            <Head title="Template DNS" />

            <PageHeader
                title="Template DNS"
                description={`Record yang dimiliki setiap zone baru akun Anda dan zone yang di-reset. Nameserver zone: ${nameservers.join(', ')}.`}
            />

            <DnsTemplatePanel
                title={own ? 'Template Anda' : 'Template admin'}
                description={
                    own
                        ? '{domain} diganti nama domain, {ip} alamat IP server. Zone yang sudah ada tidak berubah.'
                        : 'Akun Anda memakai template admin. Buat template sendiri untuk mengubahnya; template admin disalin sebagai awal.'
                }
                template={template}
                types={types}
                routes={own ? { store, update, destroy, reset } : null}
                reset={{
                    label: 'Pakai template admin',
                    title: 'Kembali ke template admin?',
                    description:
                        'Template Anda dihapus, dan zone baru akun Anda kembali memakai template admin. Zone yang sudah ada tidak berubah.',
                }}
                actions={
                    !own && (
                        <Form
                            {...copy.form()}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <Button type="submit" disabled={processing}>
                                    {processing ? <Spinner /> : <Copy />}
                                    Buat template sendiri
                                </Button>
                            )}
                        </Form>
                    )
                }
            />
        </>
    );
}
