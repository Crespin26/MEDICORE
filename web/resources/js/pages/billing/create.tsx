import { InvoiceForm, type Service } from '@/components/billing/invoice-form';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
/** Prépare le formulaire de brouillon à partir du catalogue et de la devise de la clinique. */
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
