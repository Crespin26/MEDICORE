import { displayTime, type Page } from '@/components/medical-record/entry-form';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
type Event = {
    id: number;
    actor_id: number;
    actor_name: string;
    action: string;
    subject_type: string;
    subject_id: number;
    version: number | null;
    created_at: string;
};
export default function Audit({
    patient,
    events,
    timezone,
}: {
    patient: { id: number; first_name: string; last_name: string };
    events: Page<Event>;
    timezone: string;
}) {
    const actions: Record<string, string> = {
        'record.view': 'Lecture du dossier',
        'entry.view': 'Lecture d’une information et de ses versions',
        'entry.created': 'Ajout d’une information',
        'entry.updated': 'Correction d’une information',
        'document.uploaded': 'Ajout d’un document',
        'document.downloaded': 'Téléchargement d’un document',
        'audit.view': 'Lecture du journal',
        'consultation.created': 'Création d’une consultation',
        'consultation.updated': 'Modification d’une consultation',
        'consultation.view': 'Lecture d’une consultation et de ses versions',
        'consultations.list': 'Lecture de la liste des consultations',
        'consultations.summary': 'Lecture de l’historique patient',
    };
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Patients', href: '/patients' },
                { title: 'Dossier médical', href: `/patients/${patient.id}/medical-record` },
                { title: 'Journal de mes accès', href: `/patients/${patient.id}/medical-record/audit` },
            ]}
        >
            <Head title="Journal de mes accès" />
            <main className="flex flex-col gap-6 p-6">
                <div>
                    <h1 className="text-3xl font-semibold">Journal de mes accès</h1>
                    <p className="mt-2">
                        {patient.last_name} {patient.first_name} · MC-{patient.id}
                    </p>
                    <p className="text-muted-foreground mt-2 text-sm">
                        Vos lectures et modifications depuis l’activation du journal. Le journal ne contient pas le texte des notes ni le nom des
                        documents.
                    </p>
                </div>
                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-left text-sm">
                        <thead>
                            <tr className="border-b">
                                {['Date', 'Auteur', 'Action', 'Référence'].map((label) => (
                                    <th className="p-4" key={label}>
                                        {label}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {events.data.map((event) => (
                                <tr key={event.id} className="border-b">
                                    <td className="p-4">{displayTime(event.created_at, timezone)}</td>
                                    <td className="p-4">
                                        {event.actor_name} · #{event.actor_id}
                                    </td>
                                    <td className="p-4">{actions[event.action] ?? event.action}</td>
                                    <td className="p-4">
                                        {event.subject_type} #{event.subject_id}
                                        {event.version && ` · v${event.version}`}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <nav aria-label="Journal" className="flex gap-4">
                    {events.prev_page_url && <Link href={events.prev_page_url}>Précédent</Link>}
                    <span>Page {events.current_page}</span>
                    {events.next_page_url && <Link href={events.next_page_url}>Suivant</Link>}
                </nav>
            </main>
        </AppLayout>
    );
}
