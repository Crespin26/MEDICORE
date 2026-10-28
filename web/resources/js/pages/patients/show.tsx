import { money } from '@/components/billing/invoice-form';
import { PatientForm, type Patient } from '@/components/patients/patient-form';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';

type PatientAppointment = { id: number; doctor: string; starts_at: string; status: string };

type PatientConsultation = { id: number; consulted_at: string; status: string };
export default function PatientDetails({
    patient,
    appointments,
    consultations,
    invoices,
}: {
    patient: Patient;
    appointments: PatientAppointment[];
    consultations?: PatientConsultation[];
    invoices?: { id: number; number: string | null; currency: string; status: string; total_minor: number; paid_minor: number }[];
}) {
    const { auth, status } = usePage<SharedData>().props;
    const [editing, setEditing] = useState(false);
    const canWrite = auth.permissions.includes('patients.write');
    const fields = [
        ['Prénom', patient.first_name],
        ['Nom', patient.last_name],
        ['Naissance', patient.birth_date.split('-').reverse().join('/')],
        ['Téléphone', patient.phone],
        ['E-mail', patient.email],
        ['Adresse', patient.address],
    ];
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Patients', href: '/patients' },
                { title: `MC-${patient.id}`, href: `/patients/${patient.id}` },
            ]}
        >
            <Head title={`Patient MC-${patient.id}`} />
            <main className="flex flex-col gap-6 p-6">
                <div>
                    <Link href="/patients" className="text-sm underline">
                        Retour aux patients
                    </Link>
                    <h1 className="mt-4 text-3xl font-semibold">
                        {patient.last_name} {patient.first_name}
                    </h1>
                    <p className="text-muted-foreground mt-2">Fiche patient · MC-{patient.id}</p>
                </div>
                {status && (
                    <p role="status" className="rounded-lg border p-4">
                        {status}
                    </p>
                )}
                {editing && canWrite ? (
                    <PatientForm key={patient.updated_at} patient={patient} onDone={() => setEditing(false)} />
                ) : (
                    <section className="rounded-xl border p-6">
                        <h2 className="mb-4 text-lg font-semibold">Identité et coordonnées</h2>
                        <dl className="grid gap-6 md:grid-cols-2">
                            {fields.map(([label, value]) => (
                                <div key={label}>
                                    <dt className="text-muted-foreground text-sm">{label}</dt>
                                    <dd className="mt-1 break-words">{value || 'Non renseigné'}</dd>
                                </div>
                            ))}
                        </dl>
                        {canWrite && (
                            <Button className="mt-6" onClick={() => setEditing(true)}>
                                Modifier la fiche
                            </Button>
                        )}
                    </section>
                )}
                <section className="rounded-xl border p-6">
                    <h2 className="font-semibold">Rendez-vous liés au patient</h2>
                    <p className="text-muted-foreground mt-2 text-sm">
                        Dix rendez-vous les plus récents dans l’ordre des dates, parmi ceux que vous pouvez consulter. Horaires de la clinique.
                    </p>
                    <ul className="mt-4 space-y-3">
                        {appointments.map((appointment) => (
                            <li key={appointment.id}>
                                <Link className="underline" href={`/appointments?date=${appointment.starts_at.slice(0, 10)}`}>
                                    {appointment.starts_at.slice(0, 10).split('-').reverse().join('/')} à {appointment.starts_at.slice(11)}
                                </Link>{' '}
                                · {appointment.doctor} · {appointment.status}
                            </li>
                        ))}
                    </ul>
                    {appointments.length === 0 && <p className="text-muted-foreground mt-3">Aucun rendez-vous accessible pour ce patient.</p>}
                    <p className="text-muted-foreground mt-4 text-sm">Les opérations financières sont accessibles aux rôles de facturation.</p>
                </section>
                {auth.permissions.includes('consultations.view') && (
                    <section className="rounded-xl border p-6">
                        <div className="flex flex-wrap justify-between gap-4">
                            <h2 className="font-semibold">Mes consultations de ce patient</h2>
                            <Link className="underline" href={`/patients/${patient.id}/consultations/create`}>
                                Nouvelle consultation
                            </Link>
                        </div>
                        <ul className="mt-4 space-y-3">
                            {consultations?.map((item) => (
                                <li key={item.id}>
                                    <Link className="underline" href={`/consultations/${item.id}`}>
                                        {item.consulted_at.slice(0, 10).split('-').reverse().join('/')} à {item.consulted_at.slice(11)}
                                    </Link>{' '}
                                    · {item.status === 'validated' ? 'Validé' : 'Brouillon'}
                                </li>
                            ))}
                        </ul>
                        {!consultations?.length && (
                            <p className="text-muted-foreground mt-3">Vous n’avez pas encore de consultation pour ce patient.</p>
                        )}
                        <Link className="mt-4 inline-block underline" href={`/consultations?patient_id=${patient.id}`}>
                            Voir tout mon historique pour ce patient
                        </Link>
                    </section>
                )}
                {auth.permissions.includes('medical-record.view') && (
                    <section className="rounded-xl border p-6">
                        <h2 className="font-semibold">Dossier médical</h2>
                        <p className="text-muted-foreground my-3">
                            Vos antécédents renseignés, allergies, traitements déclarés et documents privés pour ce patient.
                        </p>
                        <Link className="underline" href={`/patients/${patient.id}/medical-record`}>
                            Ouvrir le dossier médical
                        </Link>
                    </section>
                )}
                {auth.permissions.includes('billing.view') && (
                    <section className="rounded-xl border p-6">
                        <h2 className="font-semibold">Factures du patient</h2>
                        <ul className="mt-4 space-y-3">
                            {invoices?.map((invoice) => (
                                <li key={invoice.id}>
                                    <Link className="underline" href={`/billing/invoices/${invoice.id}`}>
                                        {invoice.number ?? `Brouillon #${invoice.id}`}
                                    </Link>{' '}
                                    · {money(invoice.total_minor, invoice.currency)}
                                    {invoice.status === 'cancelled'
                                        ? ' · Annulée'
                                        : ` · Solde ${money(invoice.total_minor - invoice.paid_minor, invoice.currency)}`}
                                </li>
                            ))}
                        </ul>
                        <Link className="mt-4 inline-block underline" href={`/billing?patient_id=${patient.id}`}>
                            Toutes les factures de ce patient
                        </Link>
                    </section>
                )}
            </main>
        </AppLayout>
    );
}
