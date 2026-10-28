import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';
type Item = { id: number; patient_id: number; patient: string; consulted_at: string; reason: string; status: string };
export default function Consultations({
    consultations,
    filters,
    timezone,
}: {
    consultations: { data: Item[]; total: number; current_page: number; prev_page_url: string | null; next_page_url: string | null };
    filters: { patient_id: string; status: string };
    timezone: string;
}) {
    const form = useForm(filters);
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.get('/consultations');
    };
    return (
        <AppLayout breadcrumbs={[{ title: 'Consultations', href: '/consultations' }]}>
            <Head title="Mes consultations" />
            <main className="flex flex-col gap-6 p-6">
                <div>
                    <h1 className="text-3xl font-semibold">Mes consultations</h1>
                    <p className="text-muted-foreground mt-2">Comptes rendus dont vous êtes l’auteur · {timezone}</p>
                </div>
                <form onSubmit={submit} className="flex flex-wrap items-end gap-4">
                    <div>
                        <label htmlFor="status" className="mb-2 block text-sm">
                            Statut
                        </label>
                        <select
                            id="status"
                            className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                            value={form.data.status}
                            onChange={(event) => form.setData('status', event.target.value)}
                        >
                            <option value="">Tous</option>
                            <option value="draft">Brouillons</option>
                            <option value="validated">Validés</option>
                        </select>
                        <InputError message={form.errors.status} />
                        <InputError message={form.errors.patient_id} />
                    </div>
                    <Button disabled={form.processing}>Filtrer</Button>
                    <Link href="/consultations" className="py-2 underline">
                        Réinitialiser
                    </Link>
                    <Link href="/patients" className="py-2 underline">
                        Ouvrir une fiche patient pour créer une consultation
                    </Link>
                </form>
                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-left text-sm">
                        <caption className="p-4 text-left font-semibold">{consultations.total} consultation(s)</caption>
                        <thead>
                            <tr className="border-b">
                                {['Date', 'Patient', 'Motif', 'Statut', 'Action'].map((label) => (
                                    <th key={label} className="p-4">
                                        {label}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {consultations.data.map((item) => (
                                <tr key={item.id} className="border-b">
                                    <td className="p-4 whitespace-nowrap">
                                        {item.consulted_at.slice(0, 10).split('-').reverse().join('/')} à {item.consulted_at.slice(11)}
                                    </td>
                                    <td className="p-4">
                                        {item.patient} · MC-{item.patient_id}
                                    </td>
                                    <td className="max-w-sm p-4 break-words">{item.reason}</td>
                                    <td className="p-4">{item.status === 'validated' ? 'Validé' : 'Brouillon'}</td>
                                    <td className="p-4">
                                        <Link className="underline" href={`/consultations/${item.id}`}>
                                            Ouvrir
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                            {consultations.data.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="text-muted-foreground p-6 text-center">
                                        Aucune consultation pour cette sélection.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
                <nav aria-label="Pagination" className="flex gap-4">
                    {consultations.prev_page_url && <Link href={consultations.prev_page_url}>Précédent</Link>}
                    <span>Page {consultations.current_page}</span>
                    {consultations.next_page_url && <Link href={consultations.next_page_url}>Suivant</Link>}
                </nav>
            </main>
        </AppLayout>
    );
}
