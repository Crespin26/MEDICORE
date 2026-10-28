import { ConsultationForm } from '@/components/consultations/consultation-form';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
export default function CreateConsultation({
    patient,
    appointmentId,
    now,
    timezone,
}: {
    patient: { id: number; first_name: string; last_name: string };
    appointmentId: number | null;
    now: string;
    timezone: string;
}) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Patients', href: '/patients' },
                { title: `MC-${patient.id}`, href: `/patients/${patient.id}` },
                { title: 'Nouvelle consultation', href: `/patients/${patient.id}/consultations/create` },
            ]}
        >
            <Head title="Nouvelle consultation" />
            <main className="flex flex-col gap-6 p-6">
                <div>
                    <h1 className="text-3xl font-semibold">Nouvelle consultation</h1>
                    <p className="mt-2">
                        {patient.last_name} {patient.first_name} · MC-{patient.id}
                    </p>
                    <p className="text-muted-foreground mt-2 text-sm">
                        Horaires de la clinique · {timezone}
                        {appointmentId && ` · Rendez-vous n° ${appointmentId}`}
                    </p>
                </div>
                <ConsultationForm patientId={patient.id} appointmentId={appointmentId} now={now} />
                <Link href={`/patients/${patient.id}`} className="underline">
                    Retour à la fiche patient
                </Link>
            </main>
        </AppLayout>
    );
}
