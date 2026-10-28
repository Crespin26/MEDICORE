import InputError from '@/components/input-error';
import { ProfileForm, selectClass, type Profile } from '@/components/personnel/profile-form';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
type Absence = {
    id: number;
    starts_at: string;
    ends_at: string;
    category: string;
    reason: string;
    status: string;
    version: number;
    cancel_reason: string | null;
};
type Page<T> = { data: T[]; current_page: number; prev_page_url: string | null; next_page_url: string | null };
function AbsenceForm({ userId, categories }: { userId: number; categories: Record<string, string> }) {
    const form = useForm({ starts_at: '', ends_at: '', category: 'leave', reason: '' });
    return (
        <form
            className="space-y-4 rounded-xl border p-6"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(`/personnel/${userId}/absences`, { preserveScroll: true, onSuccess: () => form.reset() });
            }}
        >
            <h2 className="font-semibold">Déclarer une absence</h2>
            <div className="grid gap-4 md:grid-cols-2">
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
            <div>
                <label htmlFor="category" className="mb-2 block text-sm">
                    Catégorie
                </label>
                <select
                    id="category"
                    className={selectClass}
                    value={form.data.category}
                    onChange={(event) => form.setData('category', event.target.value)}
                >
                    {Object.entries(categories).map(([value, label]) => (
                        <option key={value} value={value}>
                            {label}
                        </option>
                    ))}
                </select>
                <InputError message={form.errors.category} />
            </div>
            <div>
                <label htmlFor="absence_reason" className="mb-2 block text-sm">
                    Motif administratif, sans donnée médicale
                </label>
                <Input
                    id="absence_reason"
                    maxLength={500}
                    required
                    value={form.data.reason}
                    onChange={(event) => form.setData('reason', event.target.value)}
                />
                <InputError message={form.errors.reason} />
            </div>
            <Button disabled={form.processing}>Enregistrer l’absence</Button>
        </form>
    );
}
function CancelAbsence({ userId, absence }: { userId: number; absence: Absence }) {
    const form = useForm({ version: absence.version, reason: '' });
    return (
        <form
            className="mt-3 space-y-2"
            onSubmit={(event) => {
                event.preventDefault();
                if (window.confirm('Annuler cette absence et rendre la période réservable selon les disponibilités ?'))
                    form.post(`/personnel/${userId}/absences/${absence.id}/cancel`, { preserveScroll: true });
            }}
        >
            <label htmlFor={`cancel_${absence.id}`} className="block text-sm">
                Motif d’annulation
            </label>
            <Input
                id={`cancel_${absence.id}`}
                required
                maxLength={1000}
                value={form.data.reason}
                onChange={(event) => form.setData('reason', event.target.value)}
            />
            <InputError message={form.errors.reason} />
            <InputError message={form.errors.version} />
            <Button variant="outline" disabled={form.processing}>
                Annuler l’absence
            </Button>
        </form>
    );
}
export default function ProfessionalProfile({
    member,
    profile,
    absences,
    canManage,
    categories,
    timezone,
    events,
}: {
    member: { id: number; name: string; email: string; role: string; status: string };
    profile: Profile | null;
    absences: Page<Absence>;
    canManage: boolean;
    categories: Record<string, string>;
    timezone: string;
    events?: Page<{
        id: number;
        action: string;
        actor_name: string;
        created_at: string;
        reason: string | null;
        snapshot: { profile?: Profile; absence?: Absence };
    }>;
}) {
    const { auth, status } = usePage<SharedData>().props;
    const local = (value: string) => `${value.slice(0, 10).split('-').reverse().join('/')} à ${value.slice(11)}`;
    const actions: Record<string, string> = {
        'profile.created': 'Création de la fiche',
        'profile.updated': 'Correction de la fiche',
        'absence.created': 'Déclaration d’absence',
        'absence.cancelled': 'Annulation d’absence',
    };
    return (
        <AppLayout
            breadcrumbs={[
                { title: canManage ? 'Personnel' : 'Mon profil professionnel', href: canManage ? '/personnel' : '/personnel/me' },
                { title: member.name, href: canManage ? `/personnel/${member.id}` : '/personnel/me' },
            ]}
        >
            <Head title={`Profil professionnel — ${member.name}`} />
            <main className="flex flex-col gap-6 p-6">
                <div>
                    <h1 className="text-3xl font-semibold">{member.name}</h1>
                    <p className="mt-2">
                        {member.email} · Compte {{ active: 'actif', pending: 'en attente', suspended: 'suspendu' }[member.status] ?? member.status}
                    </p>
                    <p className="text-muted-foreground mt-2 text-sm">Horaires de la clinique · {timezone}</p>
                    {canManage ? (
                        <div className="mt-3 flex gap-4">
                            <Link className="underline" href="/staff">
                                Gérer les comptes et rôles
                            </Link>
                            {member.role === 'doctor' && (
                                <Link className="underline" href="/availabilities">
                                    Gérer les disponibilités
                                </Link>
                            )}
                        </div>
                    ) : (
                        auth.permissions.includes('personnel.manage') && (
                            <Link className="mt-3 inline-block underline" href={`/personnel/${member.id}`}>
                                Gérer cette fiche
                            </Link>
                        )
                    )}
                </div>
                {status && (
                    <p role="status" className="rounded-lg border p-4">
                        {status}
                    </p>
                )}
                {canManage ? (
                    <ProfileForm key={member.id} userId={member.id} profile={profile} />
                ) : (
                    <section className="rounded-xl border p-6">
                        <h2 className="font-semibold">Ma fiche professionnelle</h2>
                        {profile ? (
                            <dl className="mt-4 grid gap-4 sm:grid-cols-2">
                                {[
                                    ['Matricule', profile.employee_number],
                                    ['Fonction', profile.job_title],
                                    ['Service', profile.department],
                                    ['Spécialités', profile.specialties?.join(', ')],
                                    ['Téléphone professionnel', profile.professional_phone],
                                    ['Identifiant déclaré', profile.license_number],
                                    ['Date d’entrée', profile.started_on],
                                ].map(([label, value]) => (
                                    <div key={label}>
                                        <dt className="text-muted-foreground text-sm">{label}</dt>
                                        <dd className="mt-1 break-words">{value || 'Non renseigné'}</dd>
                                    </div>
                                ))}
                            </dl>
                        ) : (
                            <p className="text-muted-foreground mt-3">La fiche professionnelle n’a pas encore été complétée par l’administrateur.</p>
                        )}
                    </section>
                )}
                {canManage && <AbsenceForm userId={member.id} categories={categories} />}
                <section className="rounded-xl border p-6">
                    <h2 className="font-semibold">Absences</h2>
                    <ul className="mt-4 space-y-4">
                        {absences.data.map((absence) => (
                            <li key={absence.id} className="rounded-lg border p-4">
                                <p>
                                    {categories[absence.category]} · {absence.status === 'active' ? 'Déclarée' : 'Annulée'}
                                </p>
                                <p className="mt-2">
                                    {local(absence.starts_at)} → {local(absence.ends_at)}
                                </p>
                                <p className="mt-2 break-words">{absence.reason}</p>
                                {absence.cancel_reason && <p className="mt-2 break-words">Motif d’annulation : {absence.cancel_reason}</p>}
                                {canManage && absence.status === 'active' && <CancelAbsence userId={member.id} absence={absence} />}
                            </li>
                        ))}
                    </ul>
                    {!absences.data.length && <p className="text-muted-foreground mt-3">Aucune absence déclarée.</p>}
                    <nav aria-label="Absences" className="mt-4 flex gap-4">
                        {absences.prev_page_url && <Link href={absences.prev_page_url}>Précédent</Link>}
                        <span>Page {absences.current_page}</span>
                        {absences.next_page_url && <Link href={absences.next_page_url}>Suivant</Link>}
                    </nav>
                </section>
                {canManage && events && (
                    <section className="rounded-xl border p-6">
                        <h2 className="font-semibold">Historique administratif</h2>
                        <div className="mt-4 space-y-3">
                            {events.data.map((event) => (
                                <details key={event.id} className="rounded-lg border p-4">
                                    <summary className="cursor-pointer">
                                        {actions[event.action]} · {event.actor_name} ·{' '}
                                        {new Date(event.created_at).toLocaleString('fr-FR', { timeZone: timezone })}
                                    </summary>
                                    <p className="mt-3 break-words">{event.reason}</p>
                                    {event.snapshot.profile && (
                                        <dl className="mt-3 space-y-2">
                                            {Object.entries({
                                                Matricule: event.snapshot.profile.employee_number,
                                                Fonction: event.snapshot.profile.job_title,
                                                Service: event.snapshot.profile.department,
                                                Spécialités: event.snapshot.profile.specialties?.join(', '),
                                                Téléphone: event.snapshot.profile.professional_phone,
                                                'Identifiant déclaré': event.snapshot.profile.license_number,
                                                'Date d’entrée': event.snapshot.profile.started_on,
                                                Version: event.snapshot.profile.version,
                                            }).map(([label, value]) => (
                                                <div key={label}>
                                                    <dt className="text-muted-foreground text-sm">{label}</dt>
                                                    <dd className="break-words">{value || 'Non renseigné'}</dd>
                                                </div>
                                            ))}
                                        </dl>
                                    )}
                                    {event.snapshot.absence && (
                                        <p className="mt-3">
                                            {categories[event.snapshot.absence.category]} ·{' '}
                                            {new Date(event.snapshot.absence.starts_at).toLocaleString('fr-FR', { timeZone: timezone })} →{' '}
                                            {new Date(event.snapshot.absence.ends_at).toLocaleString('fr-FR', { timeZone: timezone })} ·{' '}
                                            {event.snapshot.absence.status === 'active' ? 'Déclarée' : 'Annulée'}
                                        </p>
                                    )}
                                </details>
                            ))}
                        </div>
                        <nav aria-label="Historique administratif" className="mt-4 flex gap-4">
                            {events.prev_page_url && <Link href={events.prev_page_url}>Plus récent</Link>}
                            <span>Page {events.current_page}</span>
                            {events.next_page_url && <Link href={events.next_page_url}>Plus ancien</Link>}
                        </nav>
                    </section>
                )}
            </main>
        </AppLayout>
    );
}
