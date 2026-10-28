import { BookingForm, selectClass, type Booking, type Doctor } from '@/components/appointments/booking-form';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

type Page = { data: Booking[]; current_page: number; total: number; prev_page_url: string | null; next_page_url: string | null };
type Filters = { date: string; doctor_id: string; status: string };
type Day = { date: string; count: number; items: Booking[] };
type Slot = { id: number; doctor: string; starts_at: string; ends_at: string };
const dateLabel = (date: string) =>
    new Intl.DateTimeFormat('fr-FR', { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' }).format(new Date(`${date}T12:00:00Z`));
function StatusAction({ booking, statuses, physician }: { booking: Booking; statuses: Record<string, string>; physician: boolean }) {
    const allowed = booking.transitions.filter((value) => !physician || ['arrived', 'completed', 'absent'].includes(value));
    const form = useForm({ status: '' });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (window.confirm(`Passer le rendez-vous au statut « ${statuses[form.data.status]} » ?`))
            form.patch(`/appointments/${booking.id}/status`, { preserveScroll: true, onSuccess: () => form.reset() });
    };
    return allowed.length ? (
        <form onSubmit={submit} className="flex flex-col gap-2">
            <select
                aria-label={`Nouveau statut du rendez-vous ${booking.id}`}
                className={selectClass}
                required
                value={form.data.status}
                onChange={(event) => form.setData('status', event.target.value)}
            >
                <option value="">Changer le statut</option>
                {allowed.map((value) => (
                    <option key={value} value={value}>
                        {statuses[value]}
                    </option>
                ))}
            </select>
            <InputError message={form.errors.status} />
            <Button variant="outline" disabled={form.processing}>
                Appliquer
            </Button>
        </form>
    ) : null;
}
export default function Appointments({
    appointments,
    calendar,
    doctors,
    availability,
    filters,
    timezone,
    statuses,
}: {
    appointments: Page;
    calendar: Day[];
    doctors: Doctor[];
    availability: Slot[];
    filters: Filters;
    timezone: string;
    statuses: Record<string, string>;
}) {
    const { auth, status } = usePage<SharedData>().props;
    const canWrite = auth.permissions.includes('appointments.write');
    const physician = auth.user.role === 'doctor';
    const [editing, setEditing] = useState<Booking | undefined>();
    const search = useForm<Filters>(filters);
    const href = (date: string) => `/appointments?${new URLSearchParams({ ...filters, date })}`;
    const shift = (amount: number) => {
        const date = new Date(`${filters.date}T12:00:00Z`);
        date.setUTCDate(date.getUTCDate() + amount);
        return href(date.toISOString().slice(0, 10));
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        search.get('/appointments');
    };
    return (
        <AppLayout breadcrumbs={[{ title: 'Rendez-vous', href: '/appointments' }]}>
            <Head title="Rendez-vous" />
            <main className="flex flex-col gap-6 p-6">
                <div>
                    <h1 className="text-3xl font-semibold">Rendez-vous</h1>
                    <p className="text-muted-foreground mt-2">
                        Horaires de la clinique · {timezone}
                        {physician && ' · Votre agenda'}
                    </p>
                </div>
                {status && (
                    <p role="status" className="rounded-lg border p-4">
                        {status}
                    </p>
                )}
                <form onSubmit={submit} className="grid gap-4 rounded-xl border p-4 md:grid-cols-4">
                    <div>
                        <label htmlFor="date" className="mb-2 block text-sm">
                            Date
                        </label>
                        <Input
                            id="date"
                            type="date"
                            required
                            value={search.data.date}
                            onChange={(event) => search.setData('date', event.target.value)}
                        />
                        <InputError message={search.errors.date} />
                    </div>
                    {!physician && (
                        <div>
                            <label htmlFor="filter_doctor" className="mb-2 block text-sm">
                                Médecin
                            </label>
                            <select
                                id="filter_doctor"
                                className={selectClass}
                                value={search.data.doctor_id}
                                onChange={(event) => search.setData('doctor_id', event.target.value)}
                            >
                                <option value="">Tous les médecins</option>
                                {doctors.map((doctor) => (
                                    <option key={doctor.id} value={doctor.id}>
                                        {doctor.name}
                                    </option>
                                ))}
                            </select>
                            <InputError message={search.errors.doctor_id} />
                        </div>
                    )}
                    <div>
                        <label htmlFor="filter_status" className="mb-2 block text-sm">
                            Statut
                        </label>
                        <select
                            id="filter_status"
                            className={selectClass}
                            value={search.data.status}
                            onChange={(event) => search.setData('status', event.target.value)}
                        >
                            <option value="">Tous les statuts</option>
                            {Object.entries(statuses).map(([value, label]) => (
                                <option key={value} value={value}>
                                    {label}
                                </option>
                            ))}
                        </select>
                        <InputError message={search.errors.status} />
                    </div>
                    <Button className="self-end" disabled={search.processing}>
                        Afficher l’agenda
                    </Button>
                </form>
                <section>
                    <div className="mb-4 flex flex-wrap items-center justify-between gap-4">
                        <h2 className="text-lg font-semibold">Agenda de la semaine</h2>
                        <nav className="flex gap-4" aria-label="Semaines">
                            <Link href={shift(-7)}>Semaine précédente</Link>
                            <Link href={shift(7)}>Semaine suivante</Link>
                        </nav>
                    </div>
                    <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-7">
                        {calendar.map((day) => (
                            <Link
                                key={day.date}
                                href={href(day.date)}
                                aria-current={day.date === filters.date ? 'date' : undefined}
                                className={`rounded-lg border p-3 ${day.date === filters.date ? 'border-primary bg-muted' : ''}`}
                            >
                                <h3 className="font-semibold">{dateLabel(day.date)}</h3>
                                <p className="text-muted-foreground mt-1 text-xs">{day.count} rendez-vous</p>
                                <ul className="mt-3 space-y-2">
                                    {day.items.map((item) => (
                                        <li className="text-xs" key={item.id}>
                                            <p>
                                                {item.starts_at.slice(11)} · {item.patient}
                                            </p>
                                            <p className="text-muted-foreground">{statuses[item.status]}</p>
                                        </li>
                                    ))}
                                </ul>
                                {day.count > 3 && <p className="mt-2 text-xs underline">Voir tous les rendez-vous</p>}
                            </Link>
                        ))}
                    </div>
                </section>
                {canWrite && (
                    <BookingForm
                        key={editing?.id ?? filters.date}
                        doctors={doctors}
                        date={filters.date}
                        booking={editing}
                        onDone={() => setEditing(undefined)}
                    />
                )}
                <section className="rounded-xl border p-4">
                    <div className="flex flex-wrap justify-between gap-3">
                        <h2 className="font-semibold">Disponibilités du {dateLabel(filters.date)}</h2>
                        {auth.permissions.includes('availabilities.manage') && (
                            <Link className="underline" href={`/availabilities?date=${filters.date}`}>
                                Gérer les disponibilités
                            </Link>
                        )}
                    </div>
                    <ul className="mt-3 space-y-2 text-sm">
                        {availability.map((slot) => (
                            <li key={slot.id}>
                                {slot.doctor} · {slot.starts_at.slice(11)}–{slot.ends_at.slice(11)}
                            </li>
                        ))}
                    </ul>
                    {availability.length === 0 && (
                        <p className="text-muted-foreground mt-3 text-sm">Aucune disponibilité déclarée pour cette sélection.</p>
                    )}
                </section>
                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-left text-sm">
                        <caption className="p-4 text-left font-semibold">
                            {appointments.total} rendez-vous le {dateLabel(filters.date)}
                        </caption>
                        <thead>
                            <tr className="border-b">
                                {['Horaire', 'Patient', 'Médecin', 'Statut', 'Actions'].map((label) => (
                                    <th key={label} className="p-4">
                                        {label}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {appointments.data.map((booking) => (
                                <tr key={booking.id} className="border-b align-top">
                                    <td className="p-4 whitespace-nowrap">
                                        {booking.starts_at.slice(11)}–{booking.ends_at.slice(11)}
                                    </td>
                                    <td className="p-4">
                                        <Link className="underline" href={`/patients/${booking.patient_id}`}>
                                            {booking.patient}
                                        </Link>
                                        <p className="text-muted-foreground mt-1">MC-{booking.patient_id}</p>
                                    </td>
                                    <td className="p-4">{booking.doctor}</td>
                                    <td className="p-4">{statuses[booking.status]}</td>
                                    <td className="flex flex-col gap-3 p-4">
                                        {canWrite && ['scheduled', 'confirmed'].includes(booking.status) && (
                                            <Button
                                                variant="outline"
                                                onClick={() => {
                                                    setEditing(booking);
                                                    window.scrollTo({ top: 0, behavior: 'smooth' });
                                                }}
                                            >
                                                Déplacer / modifier
                                            </Button>
                                        )}
                                        {physician && ['arrived', 'completed'].includes(booking.status) && (
                                            <Link
                                                className="underline"
                                                href={`/patients/${booking.patient_id}/consultations/create?appointment_id=${booking.id}`}
                                            >
                                                Ouvrir la consultation
                                            </Link>
                                        )}
                                        <StatusAction
                                            key={`${booking.id}-${booking.status}`}
                                            booking={booking}
                                            statuses={statuses}
                                            physician={physician}
                                        />
                                    </td>
                                </tr>
                            ))}
                            {appointments.data.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="text-muted-foreground p-6 text-center">
                                        Aucun rendez-vous pour cette sélection.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
                <nav aria-label="Pagination" className="flex gap-4">
                    {appointments.prev_page_url && <Link href={appointments.prev_page_url}>Précédent</Link>}
                    <span>Page {appointments.current_page}</span>
                    {appointments.next_page_url && <Link href={appointments.next_page_url}>Suivant</Link>}
                </nav>
            </main>
        </AppLayout>
    );
}
