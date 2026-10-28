import { PatientForm, type Patient } from '@/components/patients/patient-form';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';

export default function PatientDetails({ patient }: { patient: Patient }) {
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
                    <h2 className="font-semibold">Suivi du patient</h2>
                    <p className="text-muted-foreground mt-2">
                        Les rendez-vous, consultations et factures seront rattachés ici lorsque ces modules seront disponibles.
                    </p>
                </section>
            </main>
        </AppLayout>
    );
}
