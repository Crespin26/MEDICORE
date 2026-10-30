import { ConsultationForm, vitalFields, type Consultation, type Vitals } from '@/components/consultations/consultation-form';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
type Snapshot = { consulted_at: string; reason: string; observations: string | null; report: string | null; vitals: Vitals; status: string };
type Revision = {
    author_name: string;
    id: number;
    version: number;
    action: string;
    correction_reason: string | null;
    snapshot: Snapshot;
    created_at: string;
    author: { id: number; name: string };
};
/** Affiche le contenu clinique courant ou un instantané de révision avec les mêmes libellés. */
function ClinicalContent({ data }: { data: { reason: string; observations: string | null; report: string | null; vitals: Vitals | null } }) {
    return (
        <div className="space-y-5">
            {[
                ['Motif', data.reason],
                ['Observations', data.observations],
                ['Compte rendu', data.report],
            ].map(([label, text]) => (
                <div key={label}>
                    <h3 className="font-semibold">{label}</h3>
                    <p className="mt-2 break-words whitespace-pre-wrap">{text || 'Non renseigné'}</p>
                </div>
            ))}
            <div>
                <h3 className="font-semibold">Constantes</h3>
                <dl className="mt-3 grid gap-3 sm:grid-cols-2">
                    {vitalFields.map(({ key, label }) => (
                        <div key={key}>
                            <dt className="text-muted-foreground text-sm">{label}</dt>
                            <dd>{data.vitals?.[key] ?? 'Non renseigné'}</dd>
                        </div>
                    ))}
                </dl>
            </div>
        </div>
    );
}
/** Présente la consultation et ses révisions ; les corrections restent réservées à l’auteur. */
export default function ConsultationDetails({
    consultation,
    revisions,
    timezone,
    canUpdate,
}: {
    consultation: Consultation;
    revisions: { data: Revision[]; current_page: number; prev_page_url: string | null; next_page_url: string | null };
    timezone: string;
    canUpdate: boolean;
}) {
    const [editing, setEditing] = useState(false);
    const { status } = usePage<SharedData>().props;
    const date = (value: string) => new Date(value).toLocaleString('fr-FR', { timeZone: timezone });
    const actions: Record<string, string> = {
        created: 'Création du brouillon',
        draft_updated: 'Mise à jour du brouillon',
        validated: 'Validation',
        corrected: 'Correction après validation',
    };
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Consultations', href: '/consultations' },
                { title: `Consultation n° ${consultation.id}`, href: `/consultations/${consultation.id}` },
            ]}
        >
            <Head title={`Consultation n° ${consultation.id}`} />
            <main className="flex flex-col gap-6 p-6">
                <div>
                    <h1 className="text-3xl font-semibold">Consultation n° {consultation.id}</h1>
                    <Link className="mt-2 inline-block underline" href={`/patients/${consultation.patient_id}`}>
                        {consultation.patient.last_name} {consultation.patient.first_name} · MC-{consultation.patient_id}
                    </Link>
                    <p className="text-muted-foreground mt-2">
                        {consultation.doctor.name} · {consultation.consulted_at.slice(0, 10).split('-').reverse().join('/')} à{' '}
                        {consultation.consulted_at.slice(11)} · {timezone}
                    </p>
                    <p className="mt-2">
                        {consultation.status === 'validated' ? 'Validé' : 'Brouillon'} · Version {consultation.version}
                        {consultation.validated_at && ` · Validation initiale : ${date(consultation.validated_at)}`}
                    </p>
                </div>
                {status && (
                    <p role="status" className="rounded-lg border p-4">
                        {status}
                    </p>
                )}
                {!canUpdate && <p className="rounded-md border p-3">Lecture partagée : les corrections restent réservées au médecin auteur.</p>}
                {editing && canUpdate ? (
                    <ConsultationForm
                        key={consultation.id}
                        consultation={consultation}
                        patientId={consultation.patient_id}
                        now={consultation.consulted_at}
                        onCancel={() => setEditing(false)}
                    />
                ) : (
                    <section className="rounded-xl border p-6">
                        <ClinicalContent data={consultation} />
                        {canUpdate && (
                            <Button className="mt-6" onClick={() => setEditing(true)}>
                                {consultation.status === 'validated' ? 'Apporter une correction' : 'Modifier ou valider'}
                            </Button>
                        )}
                    </section>
                )}
                <section className="rounded-xl border p-6">
                    <h2 className="text-lg font-semibold">Historique des versions</h2>
                    <p className="text-muted-foreground mt-2 text-sm">Chaque enregistrement conserve une version, son auteur et sa date.</p>
                    <div className="mt-4 space-y-4">
                        {revisions.data.map((revision) => (
                            <details key={revision.id} className="rounded-lg border p-4">
                                <summary className="cursor-pointer">
                                    Version {revision.version} · {actions[revision.action]} · {revision.author_name} · {date(revision.created_at)}
                                </summary>
                                <div className="mt-4 space-y-4">
                                    {revision.correction_reason && (
                                        <p className="break-words whitespace-pre-wrap">
                                            <strong>Motif de correction : </strong>
                                            {revision.correction_reason}
                                        </p>
                                    )}
                                    <p>
                                        {revision.snapshot.status === 'validated' ? 'Validé' : 'Brouillon'} · Consultation du{' '}
                                        {date(revision.snapshot.consulted_at)}
                                    </p>
                                    <ClinicalContent data={revision.snapshot} />
                                </div>
                            </details>
                        ))}
                    </div>
                    <nav aria-label="Pagination des versions" className="mt-4 flex gap-4">
                        {revisions.prev_page_url && <Link href={revisions.prev_page_url}>Versions plus récentes</Link>}
                        <span>Page {revisions.current_page}</span>
                        {revisions.next_page_url && <Link href={revisions.next_page_url}>Versions plus anciennes</Link>}
                    </nav>
                </section>
            </main>
        </AppLayout>
    );
}
