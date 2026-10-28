import { selectClass, type Doctor } from '@/components/appointments/booking-form';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { type FormEvent } from 'react';
type Slot = { id: number; doctor: string; starts_at: string; ends_at: string };
function RemoveSlot({ slot }: { slot: Slot }) {
    const form = useForm({ availability: '' });
    return (
        <div>
            <Button
                variant="outline"
                disabled={form.processing}
                onClick={() => {
                    if (window.confirm('Retirer cette disponibilité ? Les créneaux avec rendez-vous actifs ne peuvent pas être retirés.'))
                        form.delete(`/availabilities/${slot.id}`, { preserveScroll: true });
                }}
            >
                Retirer
            </Button>
            <InputError message={form.errors.availability} />
        </div>
    );
}
export default function Availability({
    doctors,
    slots,
    date,
    timezone,
}: {
    doctors: Doctor[];
    slots: { data: Slot[]; current_page: number; prev_page_url: string | null; next_page_url: string | null };
    date: string;
    timezone: string;
}) {
    const { status } = usePage<SharedData>().props;
    const form = useForm({ doctor_id: doctors.length === 1 ? String(doctors[0].id) : '', starts_at: `${date}T08:00`, ends_at: `${date}T17:00` });
    const filter = useForm({ date });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/availabilities', { preserveScroll: true });
    };
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Rendez-vous', href: '/appointments' },
                { title: 'Disponibilités', href: '/availabilities' },
            ]}
        >
            <Head title="Disponibilités" />
            <main className="flex flex-col gap-6 p-6">
                <div>
                    <h1 className="text-3xl font-semibold">Disponibilités des médecins</h1>
                    <p className="text-muted-foreground mt-2">Plages datées, sans récurrence automatique · {timezone}</p>
                </div>
                {status && (
                    <p role="status" className="rounded-lg border p-4">
                        {status}
                    </p>
                )}
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        filter.get('/availabilities');
                    }}
                    className="flex flex-wrap items-end gap-4"
                >
                    <div>
                        <label htmlFor="date" className="mb-2 block text-sm">
                            Jour à consulter
                        </label>
                        <Input
                            id="date"
                            required
                            type="date"
                            value={filter.data.date}
                            onChange={(event) => filter.setData('date', event.target.value)}
                        />
                        <InputError message={filter.errors.date} />
                    </div>
                    <Button disabled={filter.processing}>Afficher</Button>
                </form>
                <form onSubmit={submit} className="rounded-xl border p-6">
                    <h2 className="mb-4 text-lg font-semibold">Ajouter une disponibilité</h2>
                    <div className="grid gap-4 md:grid-cols-3">
                        <div>
                            <label htmlFor="doctor" className="mb-2 block text-sm">
                                Médecin
                            </label>
                            <select
                                id="doctor"
                                required
                                className={selectClass}
                                value={form.data.doctor_id}
                                onChange={(event) => form.setData('doctor_id', event.target.value)}
                            >
                                <option value="">Choisir un médecin</option>
                                {doctors.map((doctor) => (
                                    <option key={doctor.id} value={doctor.id}>
                                        {doctor.name}
                                    </option>
                                ))}
                            </select>
                            <InputError message={form.errors.doctor_id} />
                        </div>
                        <div>
                            <label htmlFor="starts_at" className="mb-2 block text-sm">
                                Début
                            </label>
                            <Input
                                id="starts_at"
                                required
                                type="datetime-local"
                                value={form.data.starts_at}
                                onChange={(event) => form.setData('starts_at', event.target.value)}
                            />
                            <InputError message={form.errors.starts_at} />
                        </div>
                        <div>
                            <label htmlFor="ends_at" className="mb-2 block text-sm">
                                Fin
                            </label>
                            <Input
                                id="ends_at"
                                required
                                type="datetime-local"
                                value={form.data.ends_at}
                                onChange={(event) => form.setData('ends_at', event.target.value)}
                            />
                            <InputError message={form.errors.ends_at} />
                        </div>
                    </div>
                    <Button className="mt-4" disabled={form.processing || doctors.length === 0}>
                        Ajouter la disponibilité
                    </Button>
                    {doctors.length === 0 && <p className="mt-3 text-sm">Aucun médecin actif disponible.</p>}
                </form>
                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-left text-sm">
                        <caption className="p-4 text-left font-semibold">Plages déclarées du {date.split('-').reverse().join('/')}</caption>
                        <thead>
                            <tr className="border-b">
                                <th className="p-4">Médecin</th>
                                <th className="p-4">Horaire</th>
                                <th className="p-4">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            {slots.data.map((slot) => (
                                <tr key={slot.id} className="border-b">
                                    <td className="p-4">{slot.doctor}</td>
                                    <td className="p-4">
                                        {slot.starts_at.slice(11)}–{slot.ends_at.slice(11)}
                                    </td>
                                    <td className="p-4">
                                        <RemoveSlot slot={slot} />
                                    </td>
                                </tr>
                            ))}
                            {slots.data.length === 0 && (
                                <tr>
                                    <td colSpan={3} className="text-muted-foreground p-6 text-center">
                                        Aucune disponibilité déclarée.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
                <nav aria-label="Pagination" className="flex gap-4">
                    {slots.prev_page_url && <Link href={slots.prev_page_url}>Précédent</Link>}
                    <span>Page {slots.current_page}</span>
                    {slots.next_page_url && <Link href={slots.next_page_url}>Suivant</Link>}
                </nav>
            </main>
        </AppLayout>
    );
}
