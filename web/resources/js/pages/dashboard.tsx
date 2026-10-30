import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
type AppointmentStats = {
    statuses: Record<string, number>;
    todayCount: number;
    todayItems: { id: number; patient: string; doctor: string; time: string; status: string }[];
};
type Finance = { currency: string; gross: string; voided: string; net: string; outstanding: string };
/** Présente les agrégats autorisés, leur période et les montants séparés par devise. */
export default function Dashboard({
    patientCount,
    newPatients,
    appointments,
    consultations,
    finance,
    filters,
    today,
    timezone,
    asOf,
    csrfToken,
}: {
    patientCount: number | null;
    newPatients?: number;
    appointments?: AppointmentStats;
    consultations?: Record<string, number>;
    finance?: Finance[];
    filters: { from: string; to: string };
    today: string;
    timezone: string;
    asOf: string;
    csrfToken: string | null;
}) {
    const { auth, errors } = usePage<SharedData & { errors: Record<string, string> }>().props;
    const form = useForm(filters);
    const [exportReason, setExportReason] = useState('');
    const statuses: Record<string, string> = {
        scheduled: 'Prévus',
        confirmed: 'Confirmés',
        arrived: 'Arrivés',
        completed: 'Terminés',
        cancelled: 'Annulés',
        absent: 'Absents',
    };
    const appointmentTotal = Object.values(appointments?.statuses ?? {}).reduce((sum, count) => sum + Number(count), 0);
    return (
        <AppLayout breadcrumbs={[{ title: 'Tableau de bord', href: '/dashboard' }]}>
            <Head title="Tableau de bord" />
            <main className="flex flex-col gap-6 p-6">
                <div>
                    <h1 className="text-3xl font-semibold">Tableau de bord MediCore</h1>
                    <p className="text-muted-foreground mt-2">
                        Horaires de la clinique · {timezone} · Calculé le {new Date(asOf).toLocaleString('fr-FR', { timeZone: timezone })}
                    </p>
                </div>
                <form
                    className="flex flex-wrap items-end gap-4 rounded-xl border p-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.get('/dashboard');
                    }}
                >
                    <div>
                        <label htmlFor="from" className="mb-2 block text-sm">
                            Du
                        </label>
                        <Input id="from" type="date" required value={form.data.from} onChange={(event) => form.setData('from', event.target.value)} />
                        <InputError message={form.errors.from} />
                    </div>
                    <div>
                        <label htmlFor="to" className="mb-2 block text-sm">
                            Au (inclus)
                        </label>
                        <Input
                            id="to"
                            type="date"
                            max={today}
                            required
                            value={form.data.to}
                            onChange={(event) => form.setData('to', event.target.value)}
                        />
                        <InputError message={form.errors.to} />
                    </div>
                    <Button disabled={form.processing}>Afficher la période</Button>
                    <Link className="underline" href="/dashboard">
                        30 derniers jours
                    </Link>
                    <p className="text-muted-foreground text-sm">
                        93 jours maximum. Les valeurs « actuelles » et l’agenda du jour sont indépendants de ce filtre.
                    </p>
                </form>
                {patientCount !== null && (
                    <section className="grid gap-4 sm:grid-cols-2">
                        <Link href="/patients" className="hover:bg-muted rounded-xl border p-6">
                            <h2 className="font-semibold">Patients enregistrés actuellement</h2>
                            <p className="mt-3 text-4xl font-semibold">{patientCount}</p>
                        </Link>
                        <div className="rounded-xl border p-6">
                            <h2 className="font-semibold">Nouveaux patients sur la période</h2>
                            <p className="mt-3 text-4xl font-semibold">{newPatients ?? 0}</p>
                        </div>
                    </section>
                )}
                {appointments && (
                    <section className="rounded-xl border p-6">
                        <h2 className="text-lg font-semibold">Rendez-vous sur la période{auth.user.role === 'doctor' && ' · Mon agenda'}</h2>
                        <p className="text-muted-foreground mt-2">{appointmentTotal} rendez-vous</p>
                        {appointmentTotal ? (
                            <div className="mt-4 grid gap-4 md:grid-cols-2">
                                {Object.entries(statuses).map(([value, label]) => (
                                    <div key={value}>
                                        <div className="flex justify-between gap-3 text-sm">
                                            <span>{label}</span>
                                            <span>{appointments.statuses[value] ?? 0}</span>
                                        </div>
                                        <div aria-hidden="true" className="bg-muted mt-2 h-2 overflow-hidden rounded-full">
                                            <div
                                                className="bg-primary h-full"
                                                style={{ width: `${(Number(appointments.statuses[value] ?? 0) / appointmentTotal) * 100}%` }}
                                            />
                                        </div>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <p className="text-muted-foreground mt-4">Aucun rendez-vous sur cette période.</p>
                        )}
                    </section>
                )}
                {appointments && (
                    <section className="rounded-xl border p-6">
                        <div className="flex flex-wrap justify-between gap-4">
                            <h2 className="text-lg font-semibold">
                                Agenda du {today.split('-').reverse().join('/')} · {appointments.todayCount} rendez-vous
                            </h2>
                            <Link className="underline" href={`/appointments?date=${today}`}>
                                Ouvrir l’agenda
                            </Link>
                        </div>
                        <ul className="mt-4 space-y-3">
                            {appointments.todayItems.map((item) => (
                                <li key={item.id} className="rounded-lg border p-3">
                                    {item.time} · {item.patient} · {item.doctor} · {statuses[item.status]}
                                </li>
                            ))}
                        </ul>
                        {!appointments.todayItems.length && <p className="text-muted-foreground mt-4">Aucun rendez-vous aujourd’hui.</p>}
                        {appointments.todayCount > 8 && (
                            <p className="text-muted-foreground mt-3 text-sm">
                                Les huit premiers rendez-vous sont affichés. Ouvrez l’agenda pour la liste complète.
                            </p>
                        )}
                    </section>
                )}
                {consultations && (
                    <section className="rounded-xl border p-6">
                        <h2 className="text-lg font-semibold">Mes consultations sur la période</h2>
                        <div className="mt-4 flex flex-wrap gap-8">
                            <p>
                                Brouillons : <strong>{consultations.draft ?? 0}</strong>
                            </p>
                            <p>
                                Validées : <strong>{consultations.validated ?? 0}</strong>
                            </p>
                        </div>
                        {!Object.values(consultations).some(Number) && (
                            <p className="text-muted-foreground mt-3">Aucune consultation sur cette période.</p>
                        )}
                        <Link className="mt-4 inline-block underline" href="/consultations">
                            Mes comptes rendus
                        </Link>
                    </section>
                )}
                {finance && (
                    <section className="rounded-xl border p-6">
                        <h2 className="text-lg font-semibold">Suivi financier</h2>
                        <p className="text-muted-foreground mt-2 text-sm">
                            Paiements saisis et écritures annulées sur la période. Le solde impayé porte sur toutes les factures ouvertes
                            actuellement. Les devises sont séparées.
                        </p>
                        <div className="mt-4 overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead>
                                    <tr>
                                        {['Devise', 'Encaissements saisis', 'Paiements annulés', 'Mouvements nets', 'Impayé actuel'].map((label) => (
                                            <th className="p-3" key={label}>
                                                {label}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {finance.map((row) => (
                                        <tr className="border-t" key={row.currency}>
                                            <td className="p-3">{row.currency}</td>
                                            <td className="p-3">{row.gross}</td>
                                            <td className="p-3">{row.voided}</td>
                                            <td className="p-3">{row.net}</td>
                                            <td className="p-3">{row.outstanding}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        {!finance.length && <p className="text-muted-foreground mt-3">Aucune facture enregistrée.</p>}
                        <Link className="mt-4 inline-block underline" href="/billing">
                            Ouvrir la facturation
                        </Link>
                    </section>
                )}
                {auth.permissions.includes('administration.export') && csrfToken && (
                    <section className="rounded-xl border p-6">
                        <h2 className="text-lg font-semibold">Exporter les statistiques affichées</h2>
                        <p className="text-muted-foreground mt-2 text-sm">
                            CSV agrégé recalculé au téléchargement pour la période affichée, sans identité patient ni contenu clinique. Chaque
                            téléchargement est journalisé avec son motif.
                        </p>
                        <form method="post" action="/administration/exports" className="mt-4 space-y-3">
                            <input type="hidden" name="_token" value={csrfToken} />
                            <input type="hidden" name="from" value={filters.from} />
                            <input type="hidden" name="to" value={filters.to} />
                            <label className="block text-sm" htmlFor="export_reason">
                                Motif de l’export
                            </label>
                            <Input
                                id="export_reason"
                                name="reason"
                                required
                                maxLength={500}
                                value={exportReason}
                                onChange={(event) => setExportReason(event.target.value)}
                            />
                            <InputError message={errors.reason} />
                            <Button> Télécharger le CSV</Button>
                        </form>
                        <Link className="mt-4 inline-block underline" href="/administration/exports">
                            Journal des exports
                        </Link>
                    </section>
                )}
            </main>
        </AppLayout>
    );
}
