import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
type Patient = { id: number; first_name: string; last_name: string; birth_date: string; phone: string | null };
type Page = { data: Patient[]; prev_page_url: string | null; next_page_url: string | null; current_page: number };
export default function Patients({ patients }: { patients: Page }) {
    const canWrite = usePage<SharedData>().props.auth.permissions.includes('patients.write');
    const [editing, setEditing] = useState<number | null>(null);
    const form = useForm({ first_name: '', last_name: '', birth_date: '', phone: '' });
    const clear = () => {
        setEditing(null);
        form.reset();
        form.clearErrors();
    };
    const edit = (patient: Patient) => {
        form.clearErrors();
        setEditing(patient.id);
        form.setData({ first_name: patient.first_name, last_name: patient.last_name, birth_date: patient.birth_date, phone: patient.phone ?? '' });
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { onSuccess: clear };
        if (editing) form.patch(`/patients/${editing}`, options);
        else form.post('/patients', options);
    };
    const fields = [
        { key: 'first_name', label: 'Prénom', type: 'text' },
        { key: 'last_name', label: 'Nom', type: 'text' },
        { key: 'birth_date', label: 'Date de naissance', type: 'date' },
        { key: 'phone', label: 'Téléphone (facultatif)', type: 'tel' },
    ] as const;
    return (
        <AppLayout breadcrumbs={[{ title: 'Patients', href: '/patients' }]}>
            <Head title="Patients" />
            <main className="flex flex-col gap-6 p-6">
                <h1 className="text-3xl font-semibold">Patients</h1>
                {canWrite && (
                    <form onSubmit={submit} className="rounded-xl border p-6">
                        <h2 className="mb-4 text-lg font-semibold">{editing ? 'Modifier la fiche patient' : 'Nouveau patient'}</h2>
                        <div className="grid gap-4 md:grid-cols-2">
                            {fields.map(({ key, label, type }) => (
                                <div key={key}>
                                    <label htmlFor={key} className="mb-2 block text-sm">
                                        {label}
                                    </label>
                                    <Input
                                        id={key}
                                        type={type}
                                        value={form.data[key]}
                                        required={key !== 'phone'}
                                        maxLength={key === 'phone' ? 30 : key === 'birth_date' ? undefined : 100}
                                        onChange={(e) => form.setData(key, e.target.value)}
                                    />
                                    <InputError message={form.errors[key]} />
                                </div>
                            ))}
                        </div>
                        <div className="mt-4 flex gap-3">
                            <Button disabled={form.processing}>{editing ? 'Enregistrer les modifications' : 'Créer le patient'}</Button>
                            {editing && (
                                <Button variant="outline" type="button" onClick={clear}>
                                    Annuler
                                </Button>
                            )}
                        </div>
                    </form>
                )}
                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-left text-sm">
                        <caption className="p-4 text-left font-semibold">Patients enregistrés</caption>
                        <thead>
                            <tr className="border-b">
                                {['Référence', 'Nom', 'Naissance', 'Téléphone', 'Action'].map((label) => (
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
                                        {canWrite && (
                                            <Button variant="outline" onClick={() => edit(patient)}>
                                                Modifier
                                            </Button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                            {patients.data.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="text-muted-foreground p-6 text-center">
                                        Aucun patient enregistré.
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
