import { money, selectClass, type Invoice } from '@/components/billing/invoice-form';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
/** Affiche les factures paginées et leurs filtres, sans recalculer les soldes côté client. */
export default function Billing({
    invoices,
    filters,
    statuses,
}: {
    invoices: { data: Invoice[]; current_page: number; total: number; prev_page_url: string | null; next_page_url: string | null };
    filters: { status: string; patient_id: string };
    statuses: Record<string, string>;
}) {
    const { auth } = usePage<SharedData>().props;
    const form = useForm(filters);
    return (
        <AppLayout breadcrumbs={[{ title: 'Facturation', href: '/billing' }]}>
            <Head title="Facturation" />
            <main className="flex flex-col gap-6 p-6">
                <div className="flex flex-wrap justify-between gap-4">
                    <h1 className="text-3xl font-semibold">Facturation</h1>
                    <div className="flex gap-4">
                        <Link className="underline" href="/billing/create">
                            Nouvelle facture
                        </Link>
                        {auth.permissions.includes('catalog.manage') && (
                            <Link className="underline" href="/billing/catalog">
                                Catalogue des actes
                            </Link>
                        )}
                    </div>
                </div>
                <form
                    className="flex items-end gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.get('/billing');
                    }}
                >
                    <div>
                        <label htmlFor="status" className="mb-2 block text-sm">
                            Statut
                        </label>
                        <select
                            id="status"
                            className={selectClass}
                            value={form.data.status}
                            onChange={(event) => form.setData('status', event.target.value)}
                        >
                            <option value="">Tous</option>
                            {Object.entries(statuses).map(([value, label]) => (
                                <option key={value} value={value}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <Button disabled={form.processing}>Filtrer</Button>
                    <Link href="/billing" className="underline">
                        Réinitialiser
                    </Link>
                </form>
                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-left text-sm">
                        <caption className="p-4 text-left font-semibold">{invoices.total} facture(s)</caption>
                        <thead>
                            <tr className="border-b">
                                {['Référence', 'Patient', 'Statut', 'Total', 'Payé', 'Solde', 'Action'].map((label) => (
                                    <th key={label} className="p-4">
                                        {label}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {invoices.data.map((invoice) => (
                                <tr key={invoice.id} className="border-b">
                                    <td className="p-4">{invoice.number ?? `Brouillon #${invoice.id}`}</td>
                                    <td className="p-4">
                                        {invoice.patient_name} · MC-{invoice.patient_id}
                                    </td>
                                    <td className="p-4">{statuses[invoice.status]}</td>
                                    <td className="p-4">{money(invoice.total_minor, invoice.currency)}</td>
                                    <td className="p-4">{money(invoice.paid_minor, invoice.currency)}</td>
                                    <td className="p-4">
                                        {invoice.status === 'cancelled'
                                            ? 'Annulée'
                                            : money(invoice.total_minor - invoice.paid_minor, invoice.currency)}
                                    </td>
                                    <td className="p-4">
                                        <Link className="underline" href={`/billing/invoices/${invoice.id}`}>
                                            Ouvrir
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                            {!invoices.data.length && (
                                <tr>
                                    <td colSpan={7} className="text-muted-foreground p-6 text-center">
                                        Aucune facture.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
                <nav aria-label="Factures" className="flex gap-4">
                    {invoices.prev_page_url && <Link href={invoices.prev_page_url}>Précédent</Link>}
                    <span>Page {invoices.current_page}</span>
                    {invoices.next_page_url && <Link href={invoices.next_page_url}>Suivant</Link>}
                </nav>
            </main>
        </AppLayout>
    );
}
