import { InvoiceForm, type Service } from '@/components/billing/invoice-form';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
export default function CreateInvoice({ services, currency }: { services: Service[]; currency: string }) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Facturation', href: '/billing' },
                { title: 'Nouvelle facture', href: '/billing/create' },
            ]}
        >
            <Head title="Nouvelle facture" />
            <main className="p-6">
                <InvoiceForm services={services} currency={currency} />
            </main>
        </AppLayout>
    );
}
