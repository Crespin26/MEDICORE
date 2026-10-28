import { InvoiceForm, money, selectClass, type Invoice, type Line, type Service } from '@/components/billing/invoice-form';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
type Payment = {
    id: number;
    receipt_number: string;
    amount_minor: number;
    method: string;
    paid_at: string;
    voided_at: string | null;
    void_reason: string | null;
};
function PaymentForm({ invoice }: { invoice: Invoice }) {
    const form = useForm({ amount: '', method: 'cash', reference: '', token: crypto.randomUUID() });
    return (
        <form
            className="space-y-4 rounded-xl border p-6"
            onSubmit={(event) => {
                event.preventDefault();
                if (window.confirm('Confirmer cet encaissement ?'))
                    form.post(`/billing/invoices/${invoice.id}/payments`, {
                        preserveScroll: true,
                        onSuccess: () => {
                            form.reset();
                            form.setData('token', crypto.randomUUID());
                        },
                    });
            }}
        >
            <h2 className="font-semibold">Enregistrer un paiement · {invoice.currency}</h2>
            <InputError message={form.errors.token} />
            <div>
                <label htmlFor="amount" className="mb-2 block text-sm">
                    Montant encaissé
                </label>
                <Input
                    id="amount"
                    required
                    maxLength={32}
                    inputMode="decimal"
                    value={form.data.amount}
                    onChange={(event) => form.setData('amount', event.target.value)}
                />
                <InputError message={form.errors.amount} />
            </div>
            <div>
                <label htmlFor="method" className="mb-2 block text-sm">
                    Mode
                </label>
                <select id="method" className={selectClass} value={form.data.method} onChange={(event) => form.setData('method', event.target.value)}>
                    <option value="cash">Espèces</option>
                    <option value="transfer">Virement</option>
                    <option value="card">Carte (déjà encaissée)</option>
                </select>
                <InputError message={form.errors.method} />
            </div>
            <div>
                <label htmlFor="reference" className="mb-2 block text-sm">
                    Référence facultative (sans numéro de carte ni coordonnées bancaires)
                </label>
                <Input
                    id="reference"
                    maxLength={100}
                    value={form.data.reference}
                    onChange={(event) => form.setData('reference', event.target.value)}
                />
                <InputError message={form.errors.reference} />
            </div>
            <Button disabled={form.processing}>Enregistrer le paiement</Button>
        </form>
    );
}
function VoidPayment({ payment }: { payment: Payment }) {
    const form = useForm({ reason: '' });
    return (
        <form
            className="mt-3 flex flex-col gap-2"
            onSubmit={(event) => {
                event.preventDefault();
                if (window.confirm('Annuler cette écriture ? Aucun remboursement bancaire ne sera déclenché.'))
                    form.post(`/billing/payments/${payment.id}/void`, { preserveScroll: true });
            }}
        >
            <label htmlFor={`void_${payment.id}`} className="text-sm">
                Motif de l’annulation
            </label>
            <Input
                id={`void_${payment.id}`}
                required
                maxLength={1000}
                value={form.data.reason}
                onChange={(event) => form.setData('reason', event.target.value)}
            />
            <InputError message={form.errors.reason} />
            <Button disabled={form.processing} variant="outline">
                Annuler l’écriture
            </Button>
        </form>
    );
}
export default function InvoiceDetails({
    invoice,
    events,
    services,
    statuses,
    timezone,
}: {
    invoice: Invoice & { payments: Payment[] };
    events: {
        data: {
            id: number;
            action: string;
            actor_name: string;
            created_at: string;
            reason: string | null;
            snapshot: { lines: Line[]; invoice: Invoice };
        }[];
        current_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    services: Service[];
    statuses: Record<string, string>;
    timezone: string;
}) {
    const { auth, status } = usePage<SharedData>().props;
    const [editing, setEditing] = useState(false);
    const issue = useForm({ version: invoice.version });
    const cancel = useForm({ version: invoice.version, reason: '' });
    const date = (value: string) => new Date(value).toLocaleString('fr-FR', { timeZone: timezone });
    const actions: Record<string, string> = {
        'invoice.created': 'Création du brouillon',
        'invoice.updated': 'Correction du brouillon',
        'invoice.issued': 'Émission',
        'invoice.cancelled': 'Annulation',
        'payment.recorded': 'Encaissement',
        'payment.voided': 'Annulation de paiement',
    };
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Facturation', href: '/billing' },
                { title: invoice.number ?? `Brouillon #${invoice.id}`, href: `/billing/invoices/${invoice.id}` },
            ]}
        >
            <Head title={invoice.number ?? 'Facture brouillon'} />
            <main className="flex flex-col gap-6 p-6">
                <div>
                    <h1 className="text-3xl font-semibold">{invoice.number ?? `Brouillon #${invoice.id}`}</h1>
                    <p className="mt-2">
                        {invoice.patient_name} · MC-{invoice.patient_id} · {statuses[invoice.status]}
                    </p>
                    <a
                        className="mt-3 inline-block underline"
                        href={`/billing/invoices/${invoice.id}/print`}
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        Version imprimable
                    </a>
                </div>
                {status && (
                    <p role="status" className="rounded-lg border p-4">
                        {status}
                    </p>
                )}
                <div className="grid gap-4 sm:grid-cols-3">
                    {[
                        ['Total', invoice.total_minor],
                        ['Payé', invoice.paid_minor],
                        ['Solde', invoice.total_minor - invoice.paid_minor],
                    ].map(([label, value]) => (
                        <div key={label} className="rounded-xl border p-4">
                            <p>{label}</p>
                            <p className="mt-2 text-xl font-semibold">
                                {invoice.status === 'cancelled' && label === 'Solde' ? 'Annulée' : money(Number(value), invoice.currency)}
                            </p>
                        </div>
                    ))}
                </div>
                {editing ? (
                    <InvoiceForm
                        key={invoice.id}
                        invoice={invoice}
                        services={services}
                        currency={invoice.currency}
                        onCancel={() => setEditing(false)}
                    />
                ) : (
                    <section className="overflow-x-auto rounded-xl border p-4">
                        <table className="w-full text-left text-sm">
                            <thead>
                                <tr>
                                    {['Acte', 'Quantité', 'Prix unitaire', 'Total'].map((label) => (
                                        <th key={label} className="p-3">
                                            {label}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {invoice.lines.map((line) => (
                                    <tr key={line.id} className="border-t">
                                        <td className="p-3">
                                            {line.code} · {line.label}
                                        </td>
                                        <td className="p-3">{line.quantity}</td>
                                        <td className="p-3">{money(line.unit_price_minor, invoice.currency)}</td>
                                        <td className="p-3">{money(line.total_minor, invoice.currency)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        {invoice.status === 'draft' && (
                            <Button className="mt-4" variant="outline" onClick={() => setEditing(true)}>
                                Corriger le brouillon
                            </Button>
                        )}
                    </section>
                )}
                {invoice.status === 'draft' && (
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            if (window.confirm('Émettre cette facture et figer ses lignes ?')) {
                                issue.transform(() => ({ version: invoice.version }));
                                issue.post(`/billing/invoices/${invoice.id}/issue`);
                            }
                        }}
                    >
                        <InputError message={issue.errors.version} />
                        <Button disabled={issue.processing}>Émettre la facture</Button>
                    </form>
                )}
                {auth.permissions.includes('billing.pay') && ['issued', 'partially_paid'].includes(invoice.status) && (
                    <PaymentForm key={invoice.id} invoice={invoice} />
                )}
                <section className="rounded-xl border p-6">
                    <h2 className="font-semibold">Paiements et reçus</h2>
                    <ul className="mt-4 space-y-4">
                        {invoice.payments.map((payment) => (
                            <li key={payment.id} className="rounded-lg border p-4">
                                <p>
                                    {payment.receipt_number} · {money(payment.amount_minor, invoice.currency)} · {date(payment.paid_at)}
                                </p>
                                <a
                                    className="mt-2 inline-block underline"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    href={`/billing/receipts/${payment.id}`}
                                >
                                    Imprimer le reçu
                                </a>
                                {payment.voided_at ? (
                                    <p className="mt-2">Écriture annulée : {payment.void_reason}</p>
                                ) : (
                                    auth.permissions.includes('billing.reverse') && <VoidPayment payment={payment} />
                                )}
                            </li>
                        ))}
                    </ul>
                    {!invoice.payments.length && <p className="text-muted-foreground mt-3">Aucun paiement enregistré.</p>}
                </section>
                {auth.permissions.includes('billing.reverse') && invoice.status !== 'cancelled' && (
                    <form
                        className="space-y-3 rounded-xl border p-6"
                        onSubmit={(event) => {
                            event.preventDefault();
                            if (window.confirm('Annuler cette facture en conservant son historique ?')) {
                                cancel.transform((data) => ({ ...data, version: invoice.version }));
                                cancel.post(`/billing/invoices/${invoice.id}/cancel`);
                            }
                        }}
                    >
                        <h2 className="font-semibold">Annuler la facture</h2>
                        <label htmlFor="cancel_reason" className="block text-sm">
                            Motif
                        </label>
                        <Input
                            id="cancel_reason"
                            required
                            maxLength={1000}
                            value={cancel.data.reason}
                            onChange={(event) => cancel.setData('reason', event.target.value)}
                        />
                        <InputError message={cancel.errors.reason} />
                        <InputError message={cancel.errors.version} />
                        <Button variant="outline" disabled={cancel.processing || invoice.paid_minor > 0}>
                            Annuler la facture
                        </Button>
                        {invoice.paid_minor > 0 && (
                            <p className="text-muted-foreground text-sm">Les écritures de paiement actives doivent être annulées d’abord.</p>
                        )}
                    </form>
                )}
                <section className="rounded-xl border p-6">
                    <h2 className="font-semibold">Historique</h2>
                    <div className="mt-4 space-y-3">
                        {events.data.map((event) => (
                            <details key={event.id} className="rounded-lg border p-4">
                                <summary className="cursor-pointer">
                                    {actions[event.action] ?? event.action} · {event.actor_name} · {date(event.created_at)}
                                </summary>
                                <p className="mt-3 break-words">{event.reason}</p>
                                <p className="mt-2">
                                    Version {event.snapshot.invoice.version} · {statuses[event.snapshot.invoice.status]} · Total{' '}
                                    {money(event.snapshot.invoice.total_minor, event.snapshot.invoice.currency)} · Payé{' '}
                                    {money(event.snapshot.invoice.paid_minor, event.snapshot.invoice.currency)}
                                </p>
                                <ul className="mt-3 space-y-2">
                                    {event.snapshot.lines.map((line) => (
                                        <li key={line.id}>
                                            {line.label} · {line.quantity} × {money(line.unit_price_minor, invoice.currency)}
                                        </li>
                                    ))}
                                </ul>
                            </details>
                        ))}
                    </div>
                    <nav className="mt-4 flex gap-4" aria-label="Historique">
                        {events.prev_page_url && <Link href={events.prev_page_url}>Plus récent</Link>}
                        <span>Page {events.current_page}</span>
                        {events.next_page_url && <Link href={events.next_page_url}>Plus ancien</Link>}
                    </nav>
                </section>
            </main>
        </AppLayout>
    );
}
