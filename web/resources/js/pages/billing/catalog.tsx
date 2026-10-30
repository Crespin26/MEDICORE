import { major, money, type Service } from '@/components/billing/invoice-form';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
/** Convertit le prix pour la saisie puis transmet la prestation et sa version au serveur. */
function CatalogForm({ currency, service, onDone }: { currency: string; service?: Service; onDone: () => void }) {
    const form = useForm({
        currency: service?.currency ?? currency,
        code: service?.code ?? '',
        name: service?.name ?? '',
        price: service ? major(service.price_minor, service.currency) : '',
        active: service?.active ?? true,
        version: service?.version ?? 1,
        reason: '',
    });
    return (
        <form
            className="space-y-4 rounded-xl border p-6"
            onSubmit={(event) => {
                event.preventDefault();
                const options = {
                    preserveScroll: true,
                    onSuccess: () => {
                        form.reset();
                        onDone();
                    },
                };
                if (service) form.patch(`/billing/catalog/${service.id}`, options);
                else form.post('/billing/catalog', options);
            }}
        >
            <h2 className="font-semibold">
                {service ? 'Corriger un acte' : 'Nouvel acte'} · {service?.currency ?? currency}
            </h2>
            <InputError message={form.errors.version} />
            {(['code', 'name', 'price'] as const).map((key) => (
                <div key={key}>
                    <label htmlFor={key} className="mb-2 block text-sm">
                        {{ code: 'Code (majuscules, chiffres, tirets)', name: 'Libellé administratif', price: 'Prix unitaire' }[key]}
                    </label>
                    <Input
                        id={key}
                        required
                        maxLength={key === 'code' ? 40 : key === 'name' ? 255 : 32}
                        value={form.data[key]}
                        onChange={(event) => form.setData(key, event.target.value)}
                    />
                    <InputError message={form.errors[key]} />
                </div>
            ))}
            <label className="flex items-center gap-2">
                <input type="checkbox" checked={form.data.active} onChange={(event) => form.setData('active', event.target.checked)} />
                Actif
            </label>
            <InputError message={form.errors.active} />
            {service && (
                <div>
                    <label htmlFor="reason" className="mb-2 block text-sm">
                        Motif de correction
                    </label>
                    <Input
                        id="reason"
                        required
                        maxLength={1000}
                        value={form.data.reason}
                        onChange={(event) => form.setData('reason', event.target.value)}
                    />
                    <InputError message={form.errors.reason} />
                </div>
            )}
            <div className="flex gap-3">
                <Button disabled={form.processing}>Enregistrer</Button>
                {service && (
                    <Button variant="outline" type="button" disabled={form.processing} onClick={onDone}>
                        Annuler
                    </Button>
                )}
            </div>
        </form>
    );
}
/** Présente les prestations et leurs formulaires de gestion versionnée. */
export default function Catalog({
    services,
    currency,
    events,
    timezone,
}: {
    services: { data: Service[]; current_page: number; prev_page_url: string | null; next_page_url: string | null };
    currency: string;
    timezone: string;
    events: {
        data: { id: number; actor_name: string; reason: string | null; action: string; created_at: string; snapshot: { service: Service } }[];
        current_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
}) {
    const [editing, setEditing] = useState<Service | undefined>();
    const { status } = usePage<SharedData>().props;
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Facturation', href: '/billing' },
                { title: 'Catalogue des actes', href: '/billing/catalog' },
            ]}
        >
            <Head title="Catalogue des actes" />
            <main className="flex flex-col gap-6 p-6">
                <h1 className="text-3xl font-semibold">Catalogue des actes</h1>
                {status && (
                    <p role="status" className="rounded-lg border p-4">
                        {status}
                    </p>
                )}
                <CatalogForm key={editing?.id ?? 'new'} service={editing} currency={currency} onDone={() => setEditing(undefined)} />
                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-left text-sm">
                        <thead>
                            <tr className="border-b">
                                {['Code', 'Libellé', 'Prix', 'État', 'Action'].map((label) => (
                                    <th key={label} className="p-4">
                                        {label}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {services.data.map((service) => (
                                <tr key={service.id} className="border-b">
                                    <td className="p-4">{service.code}</td>
                                    <td className="p-4">{service.name}</td>
                                    <td className="p-4">{money(service.price_minor, service.currency)}</td>
                                    <td className="p-4">{service.active ? 'Actif' : 'Inactif'}</td>
                                    <td className="p-4">
                                        <Button
                                            variant="outline"
                                            onClick={() => {
                                                setEditing(service);
                                                window.scrollTo({ top: 0, behavior: 'smooth' });
                                            }}
                                        >
                                            Modifier
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                            {!services.data.length && (
                                <tr>
                                    <td colSpan={5} className="p-6 text-center">
                                        Aucun acte dans le catalogue.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
                <nav aria-label="Catalogue" className="flex gap-4">
                    {services.prev_page_url && <Link href={services.prev_page_url}>Précédent</Link>}
                    <span>Page {services.current_page}</span>
                    {services.next_page_url && <Link href={services.next_page_url}>Suivant</Link>}
                </nav>
                <section className="rounded-xl border p-6">
                    <h2 className="font-semibold">Historique du catalogue</h2>
                    <div className="mt-4 space-y-3">
                        {events.data.map((event) => (
                            <details key={event.id} className="rounded-lg border p-3">
                                <summary className="cursor-pointer">
                                    {event.snapshot.service.code} · {event.action === 'catalog.created' ? 'Création' : 'Correction'} ·{' '}
                                    {event.actor_name} · {new Date(event.created_at).toLocaleString('fr-FR', { timeZone: timezone })}
                                </summary>
                                <p className="mt-3">
                                    {event.snapshot.service.name} · {money(event.snapshot.service.price_minor, event.snapshot.service.currency)} ·{' '}
                                    {event.snapshot.service.active ? 'Actif' : 'Inactif'} · v{event.snapshot.service.version}
                                </p>
                                <p className="mt-2 break-words">{event.reason}</p>
                            </details>
                        ))}
                    </div>
                    <nav aria-label="Historique du catalogue" className="mt-4 flex gap-4">
                        {events.prev_page_url && <Link href={events.prev_page_url}>Plus récent</Link>}
                        <span>Page {events.current_page}</span>
                        {events.next_page_url && <Link href={events.next_page_url}>Plus ancien</Link>}
                    </nav>
                </section>
            </main>
        </AppLayout>
    );
}
