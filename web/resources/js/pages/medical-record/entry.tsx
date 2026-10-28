import { EntryForm, displayTime, type Entry, type Page } from '@/components/medical-record/entry-form';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
type Revision = {
    id: number;
    version: number;
    author_name: string;
    author_id: number;
    created_at: string;
    correction_reason: string | null;
    snapshot: Entry;
};
export default function MedicalEntry({
    patient,
    entry,
    revisions,
    kinds,
    timezone,
}: {
    patient: { id: number; first_name: string; last_name: string };
    entry: Entry;
    revisions: Page<Revision>;
    kinds: Record<string, string>;
    timezone: string;
}) {
    const [editing, setEditing] = useState(false);
    const { status } = usePage<SharedData>().props;
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Patients', href: '/patients' },
                { title: 'Dossier médical', href: `/patients/${patient.id}/medical-record` },
                { title: `Information n° ${entry.id}`, href: `/patients/${patient.id}/medical-entries/${entry.id}` },
            ]}
        >
            <Head title={`Information médicale n° ${entry.id}`} />
            <main className="flex flex-col gap-6 p-6">
                <div>
                    <h1 className="text-3xl font-semibold">{kinds[entry.kind]}</h1>
                    <p className="mt-2">
                        {patient.last_name} {patient.first_name} · MC-{patient.id} · Version {entry.version}
                    </p>
                </div>
                {status && (
                    <p role="status" className="rounded-lg border p-4">
                        {status}
                    </p>
                )}
                {editing ? (
                    <EntryForm key={entry.id} patientId={patient.id} entry={entry} kinds={kinds} onCancel={() => setEditing(false)} />
                ) : (
                    <section className="rounded-xl border p-6">
                        <h2 className="text-xl font-semibold break-words">{entry.label}</h2>
                        <p className="mt-3">
                            {entry.status === 'active' ? 'Actif' : 'Résolu / terminé'}
                            {entry.occurred_on && ` · Survenue / début : ${entry.occurred_on.split('-').reverse().join('/')}`}
                        </p>
                        <p className="mt-4 break-words whitespace-pre-wrap">{entry.details || 'Aucun détail supplémentaire.'}</p>
                        <Button className="mt-4" onClick={() => setEditing(true)}>
                            Corriger l’information
                        </Button>
                    </section>
                )}
                <section className="rounded-xl border p-6">
                    <h2 className="text-lg font-semibold">Historique des versions</h2>
                    <div className="mt-4 space-y-3">
                        {revisions.data.map((revision) => (
                            <details key={revision.id} className="rounded-lg border p-4">
                                <summary className="cursor-pointer">
                                    Version {revision.version} · {revision.author_name} · {displayTime(revision.created_at, timezone)}
                                </summary>
                                <div className="mt-4 space-y-3">
                                    <p>
                                        {kinds[revision.snapshot.kind]} · {revision.snapshot.status === 'active' ? 'Actif' : 'Résolu / terminé'}
                                    </p>
                                    <p className="font-semibold break-words">{revision.snapshot.label}</p>
                                    <p className="break-words whitespace-pre-wrap">{revision.snapshot.details}</p>
                                    <p>Date de survenue : {revision.snapshot.occurred_on?.slice(0, 10) || 'Non renseignée'}</p>
                                    {revision.correction_reason && (
                                        <p className="break-words whitespace-pre-wrap">
                                            <strong>Motif : </strong>
                                            {revision.correction_reason}
                                        </p>
                                    )}
                                </div>
                            </details>
                        ))}
                    </div>
                    <nav aria-label="Versions" className="mt-4 flex gap-4">
                        {revisions.prev_page_url && <Link href={revisions.prev_page_url}>Plus récentes</Link>}
                        <span>Page {revisions.current_page}</span>
                        {revisions.next_page_url && <Link href={revisions.next_page_url}>Plus anciennes</Link>}
                    </nav>
                </section>
            </main>
        </AppLayout>
    );
}
