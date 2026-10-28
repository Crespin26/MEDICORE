import InputError from '@/components/input-error';
import { EntryForm, displayTime, type Entry, type Page } from '@/components/medical-record/entry-form';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useRef, type FormEvent } from 'react';
type Patient = { id: number; first_name: string; last_name: string };
type Document = { id: number; title: string; original_name: string; size: number; created_at: string };
type Timeline = { id: number; type: 'entry' | 'document' | 'consultation'; event_at: string };
function Pagination({ page, label }: { page: Page<unknown>; label: string }) {
    return (
        <nav aria-label={label} className="mt-4 flex gap-4">
            {page.prev_page_url && <Link href={page.prev_page_url}>Précédent</Link>}
            <span>Page {page.current_page}</span>
            {page.next_page_url && <Link href={page.next_page_url}>Suivant</Link>}
        </nav>
    );
}
export default function MedicalRecord({
    patient,
    entries,
    documents,
    timeline,
    kinds,
    kind,
    timezone,
}: {
    patient: Patient;
    entries: Page<Entry>;
    documents: Page<Document>;
    timeline: Page<Timeline>;
    kinds: Record<string, string>;
    kind: string;
    timezone: string;
}) {
    const { status } = usePage<SharedData>().props;
    const fileInput = useRef<HTMLInputElement>(null);
    const upload = useForm<{ title: string; file: File | null }>({ title: '', file: null });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        upload.post(`/patients/${patient.id}/medical-documents`, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                upload.reset();
                if (fileInput.current) fileInput.current.value = '';
            },
        });
    };
    const sourceNames = { entry: 'Information médicale (dernière modification)', document: 'Document ajouté', consultation: 'Consultation' };
    const target = (item: Timeline) =>
        item.type === 'consultation'
            ? `/consultations/${item.id}`
            : item.type === 'entry'
              ? `/patients/${patient.id}/medical-entries/${item.id}`
              : `/patients/${patient.id}/medical-documents/${item.id}/download`;
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Patients', href: '/patients' },
                { title: `MC-${patient.id}`, href: `/patients/${patient.id}` },
                { title: 'Dossier médical', href: `/patients/${patient.id}/medical-record` },
            ]}
        >
            <Head title={`Dossier médical MC-${patient.id}`} />
            <main className="flex flex-col gap-6 p-6">
                <div>
                    <h1 className="text-3xl font-semibold">Dossier médical</h1>
                    <p className="mt-2">
                        {patient.last_name} {patient.first_name} · MC-{patient.id}
                    </p>
                    <p className="text-muted-foreground mt-2 text-sm">
                        Informations dont vous êtes l’auteur · {timezone}. Une liste vide ne signifie pas une absence d’antécédents ou d’allergies.
                    </p>
                    <Link className="mt-3 inline-block underline" href={`/patients/${patient.id}/medical-record/audit`}>
                        Voir mes accès et modifications
                    </Link>
                </div>
                {status && (
                    <p role="status" className="rounded-lg border p-4">
                        {status}
                    </p>
                )}
                <EntryForm patientId={patient.id} kinds={kinds} />
                <section className="rounded-xl border p-6">
                    <h2 className="text-lg font-semibold">Antécédents, allergies et traitements déclarés</h2>
                    <nav aria-label="Catégories" className="my-4 flex flex-wrap gap-4">
                        <Link aria-current={!kind ? 'page' : undefined} href={`/patients/${patient.id}/medical-record`}>
                            Toutes
                        </Link>
                        {Object.entries(kinds).map(([value, label]) => (
                            <Link
                                key={value}
                                className={kind === value ? 'font-semibold underline' : 'underline'}
                                href={`/patients/${patient.id}/medical-record?kind=${value}`}
                            >
                                {label}
                            </Link>
                        ))}
                    </nav>
                    <ul className="space-y-4">
                        {entries.data.map((entry) => (
                            <li key={entry.id} className="rounded-lg border p-4">
                                <p className="text-muted-foreground text-sm">
                                    {kinds[entry.kind]} · {entry.status === 'active' ? 'Actif' : 'Résolu / terminé'}
                                </p>
                                <Link
                                    className="mt-2 inline-block font-semibold break-words underline"
                                    href={`/patients/${patient.id}/medical-entries/${entry.id}`}
                                >
                                    {entry.label}
                                </Link>
                                <p className="mt-2 break-words whitespace-pre-wrap">{entry.details}</p>
                                {entry.occurred_on && (
                                    <p className="mt-2 text-sm">Survenue / début : {entry.occurred_on.split('-').reverse().join('/')}</p>
                                )}
                            </li>
                        ))}
                    </ul>
                    {!entries.data.length && <p className="text-muted-foreground">Aucune information renseignée dans cette sélection.</p>}
                    <Pagination page={entries} label="Informations médicales" />
                </section>
                <section className="rounded-xl border p-6">
                    <h2 className="text-lg font-semibold">Documents privés</h2>
                    <form onSubmit={submit} className="my-4 space-y-4">
                        <div>
                            <label htmlFor="title" className="mb-2 block text-sm">
                                Titre du document
                            </label>
                            <Input
                                id="title"
                                required
                                maxLength={255}
                                value={upload.data.title}
                                onChange={(event) => upload.setData('title', event.target.value)}
                            />
                            <InputError message={upload.errors.title} />
                        </div>
                        <div>
                            <label htmlFor="file" className="mb-2 block text-sm">
                                PDF, JPEG ou PNG · 10 Mo maximum
                            </label>
                            <Input
                                ref={fileInput}
                                id="file"
                                type="file"
                                required
                                accept=".pdf,.jpg,.jpeg,.png"
                                onChange={(event) => upload.setData('file', event.target.files?.[0] ?? null)}
                            />
                            <InputError message={upload.errors.file} />
                        </div>
                        {upload.progress && <p role="status">Envoi : {upload.progress.percentage}%</p>}
                        <Button disabled={upload.processing}>Ajouter le document</Button>
                    </form>
                    <ul className="space-y-3">
                        {documents.data.map((document) => (
                            <li key={document.id} className="rounded-lg border p-3">
                                <a className="break-words underline" href={`/patients/${patient.id}/medical-documents/${document.id}/download`}>
                                    {document.title}
                                </a>
                                <p className="text-muted-foreground mt-1 text-sm break-words">
                                    {document.original_name} · {Math.ceil(document.size / 1024)} Ko · {displayTime(document.created_at, timezone)}
                                </p>
                            </li>
                        ))}
                    </ul>
                    {!documents.data.length && <p className="text-muted-foreground">Aucun document ajouté.</p>}
                    <Pagination page={documents} label="Documents privés" />
                </section>
                <section className="rounded-xl border p-6">
                    <h2 className="text-lg font-semibold">Chronologie</h2>
                    <p className="text-muted-foreground mt-2 text-sm">
                        Consultations par date clinique, informations par dernière modification, documents par date d’ajout.
                    </p>
                    <ol className="mt-4 space-y-3">
                        {timeline.data.map((item) => (
                            <li key={`${item.type}-${item.id}`}>
                                {displayTime(item.event_at, timezone)} ·{' '}
                                {item.type === 'document' ? (
                                    <a className="underline" href={target(item)}>
                                        {sourceNames[item.type]} n° {item.id}
                                    </a>
                                ) : (
                                    <Link className="underline" href={target(item)}>
                                        {sourceNames[item.type]} n° {item.id}
                                    </Link>
                                )}
                            </li>
                        ))}
                    </ol>
                    {!timeline.data.length && <p className="text-muted-foreground mt-3">Aucun événement accessible.</p>}
                    <Pagination page={timeline} label="Chronologie" />
                </section>
            </main>
        </AppLayout>
    );
}
