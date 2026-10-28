import InputError from '@/components/input-error';
import { PatientForm, type Patient } from '@/components/patients/patient-form';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

type Page = { data: Patient[]; prev_page_url: string | null; next_page_url: string | null; current_page: number; total: number };
type Filters = { search: string; birth_from: string; birth_to: string; contact: string };
export default function Patients({ patients, filters }: { patients: Page; filters: Filters }) {
    const { auth, status } = usePage<SharedData>().props;
    const canWrite = auth.permissions.includes('patients.write');
    const [editing, setEditing] = useState<Patient | undefined>();
    const search = useForm<Filters>({
        search: filters.search ?? '',
        birth_from: filters.birth_from ?? '',
        birth_to: filters.birth_to ?? '',
        contact: filters.contact ?? '',
    });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        search.get('/patients', { preserveState: true, replace: true });
    };
    const edit = (patient: Patient) => {
        setEditing(patient);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };
    return (
        <AppLayout breadcrumbs={[{ title: 'Patients', href: '/patients' }]}>
            <Head title="Patients" />
            <main className="flex flex-col gap-6 p-6">
                <h1 className="text-3xl font-semibold">Patients</h1>
                {status && (
                    <p role="status" className="rounded-lg border p-4">
                        {status}
                    </p>
                )}
                {canWrite && <PatientForm key={editing?.id ?? 'new'} patient={editing} onDone={() => setEditing(undefined)} />}
                <form onSubmit={submit} className="rounded-xl border p-6">
                    <h2 className="mb-4 font-semibold">Rechercher un patient</h2>
                    <div className="grid gap-4 md:grid-cols-2">
                        <div>
                            <label htmlFor="search" className="mb-2 block text-sm">
                                Nom, prénom, téléphone, e-mail ou référence MC-…
                            </label>
                            <Input
                                id="search"
                                type="search"
                                maxLength={100}
                                value={search.data.search}
                                onChange={(e) => search.setData('search', e.target.value)}
                            />
                            <InputError message={search.errors.search} />
                        </div>
                        <div>
                            <label htmlFor="contact" className="mb-2 block text-sm">
                                Coordonnées téléphoniques
                            </label>
                            <select
                                id="contact"
                                className="border-input bg-background w-full rounded-md border px-3 py-2 text-sm"
                                value={search.data.contact}
                                onChange={(e) => search.setData('contact', e.target.value)}
                            >
                                <option value="">Tous les patients</option>
                                <option value="with_phone">Avec téléphone</option>
                                <option value="without_phone">Sans téléphone</option>
                            </select>
                            <InputError message={search.errors.contact} />
                        </div>
                        <div>
                            <label htmlFor="birth_from" className="mb-2 block text-sm">
                                Né à partir du
                            </label>
                            <Input
                                id="birth_from"
                                type="date"
                                value={search.data.birth_from}
                                onChange={(e) => search.setData('birth_from', e.target.value)}
                            />
                            <InputError message={search.errors.birth_from} />
                        </div>
                        <div>
                            <label htmlFor="birth_to" className="mb-2 block text-sm">
                                Né jusqu’au
                            </label>
                            <Input
                                id="birth_to"
                                type="date"
                                value={search.data.birth_to}
                                onChange={(e) => search.setData('birth_to', e.target.value)}
                            />
                            <InputError message={search.errors.birth_to} />
                        </div>
                    </div>
                    <div className="mt-4 flex items-center gap-4">
                        <Button disabled={search.processing}>Rechercher</Button>
                        <Link href="/patients">Réinitialiser les filtres</Link>
                    </div>
                </form>
                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-left text-sm">
                        <caption className="p-4 text-left font-semibold">{patients.total} patient(s) trouvé(s)</caption>
                        <thead>
                            <tr className="border-b">
                                {['Référence', 'Nom', 'Naissance', 'Téléphone', 'Actions'].map((label) => (
                                    <th className="p-4" key={label}>
                                        {label}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {patients.data.map((patient) => (
                                <tr key={patient.id} className="border-b">
                                    <td className="p-4">MC-{patient.id}</td>
                                    <td className="p-4">
                                        {patient.last_name} {patient.first_name}
                                    </td>
                                    <td className="p-4">{patient.birth_date.split('-').reverse().join('/')}</td>
                                    <td className="p-4">{patient.phone ?? '—'}</td>
                                    <td className="p-4">
                                        <div className="flex items-center gap-3">
                                            <Link href={`/patients/${patient.id}`} className="underline">
                                                Voir la fiche
                                            </Link>
                                            {canWrite && (
                                                <Button variant="outline" onClick={() => edit(patient)}>
                                                    Modifier
                                                </Button>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                            {patients.data.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="text-muted-foreground p-6 text-center">
                                        Aucun patient ne correspond aux critères.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
                <nav aria-label="Pagination" className="flex items-center gap-4">
                    {patients.prev_page_url && <Link href={patients.prev_page_url}>Précédent</Link>}
                    <span>Page {patients.current_page}</span>
                    {patients.next_page_url && <Link href={patients.next_page_url}>Suivant</Link>}
                </nav>
            </main>
        </AppLayout>
    );
}
