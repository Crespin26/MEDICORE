import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useForm } from '@inertiajs/react';
import { useEffect, useState, type FormEvent } from 'react';
export type Doctor = { id: number; name: string };
export type Booking = {
    id: number;
    patient_id: number;
    doctor_id: number;
    patient: string;
    doctor: string;
    starts_at: string;
    ends_at: string;
    status: string;
    transitions: string[];
};
type PatientResult = { id: number; first_name: string; last_name: string; birth_date: string };
export const selectClass = 'border-input bg-background w-full rounded-md border px-3 py-2 text-sm';

export function BookingForm({ doctors, date, booking, onDone }: { doctors: Doctor[]; date: string; booking?: Booking; onDone: () => void }) {
    const form = useForm({
        patient_id: booking ? String(booking.patient_id) : '',
        doctor_id: booking ? String(booking.doctor_id) : '',
        starts_at: booking?.starts_at ?? `${date}T09:00`,
        ends_at: booking?.ends_at ?? `${date}T09:30`,
    });
    const [query, setQuery] = useState('');
    const [selected, setSelected] = useState(booking?.patient ?? '');
    const [results, setResults] = useState<PatientResult[]>([]);
    const [lookupStatus, setLookupStatus] = useState('');
    useEffect(() => {
        const controller = new AbortController();
        if (query.trim().length < 2) {
            setResults([]);
            setLookupStatus('');
            return;
        }
        const timer = setTimeout(async () => {
            setLookupStatus('Recherche…');
            try {
                const response = await fetch(`/appointments/patients?${new URLSearchParams({ search: query })}`, {
                    signal: controller.signal,
                    headers: { Accept: 'application/json' },
                });
                if (!response.ok) throw new Error('lookup');
                const data: PatientResult[] = await response.json();
                if (!controller.signal.aborted) {
                    setResults(data);
                    setLookupStatus(data.length ? '' : 'Aucun patient trouvé.');
                }
            } catch {
                if (!controller.signal.aborted) setLookupStatus('Recherche indisponible. Vérifiez votre connexion puis réessayez.');
            }
        }, 250);
        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [query]);
    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setSelected('');
                setQuery('');
                onDone();
            },
        };
        if (booking) form.patch(`/appointments/${booking.id}`, options);
        else form.post('/appointments', options);
    };
    return (
        <form className="rounded-xl border p-6" onSubmit={submit}>
            <h2 className="mb-4 text-lg font-semibold">{booking ? 'Déplacer ou modifier le rendez-vous' : 'Planifier un rendez-vous'}</h2>
            <div className="grid gap-4 md:grid-cols-2">
                <div>
                    <label htmlFor="patient_search" className="mb-2 block text-sm">
                        Rechercher un patient (2 caractères minimum)
                    </label>
                    <Input id="patient_search" type="search" maxLength={100} value={query} onChange={(event) => setQuery(event.target.value)} />
                    <p role="status" className="text-muted-foreground mt-2 text-sm">
                        {lookupStatus}
                    </p>
                    {results.length > 0 && (
                        <ul className="max-h-48 overflow-y-auto rounded-md border">
                            {results.map((patient) => (
                                <li key={patient.id}>
                                    <button
                                        type="button"
                                        className="hover:bg-muted w-full p-2 text-left text-sm"
                                        onClick={() => {
                                            form.setData('patient_id', String(patient.id));
                                            setSelected(`${patient.last_name} ${patient.first_name}`);
                                            setQuery('');
                                        }}
                                    >
                                        {patient.last_name} {patient.first_name} · MC-{patient.id} ·{' '}
                                        {patient.birth_date.split('-').reverse().join('/')}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                    <p className="mt-2 text-sm">
                        {selected ? `Patient sélectionné : ${selected} · MC-${form.data.patient_id}` : 'Aucun patient sélectionné.'}
                    </p>
                    <InputError message={form.errors.patient_id} />
                </div>
                <div>
                    <label htmlFor="doctor_id" className="mb-2 block text-sm">
                        Médecin
                    </label>
                    <select
                        id="doctor_id"
                        className={selectClass}
                        required
                        value={form.data.doctor_id}
                        onChange={(event) => form.setData('doctor_id', event.target.value)}
                    >
                        <option value="">Choisir un médecin actif</option>
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
                        type="datetime-local"
                        required
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
                        type="datetime-local"
                        required
                        value={form.data.ends_at}
                        onChange={(event) => form.setData('ends_at', event.target.value)}
                    />
                    <InputError message={form.errors.ends_at} />
                </div>
            </div>
            {doctors.length === 0 && (
                <p className="mt-4 text-sm">Aucun médecin actif. Invitez et activez un compte médecin depuis les comptes du personnel.</p>
            )}
            <div className="mt-4 flex gap-3">
                <Button disabled={form.processing || doctors.length === 0}>{booking ? 'Enregistrer le déplacement' : 'Planifier'}</Button>
                {booking && (
                    <Button type="button" variant="outline" disabled={form.processing} onClick={onDone}>
                        Annuler la modification
                    </Button>
                )}
            </div>
        </form>
    );
}
