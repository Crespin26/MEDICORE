import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
type Day = { enabled: boolean; opens: string; closes: string };
type Setting = {
    id: number;
    name: string;
    phone: string | null;
    email: string | null;
    address: string | null;
    currency: string;
    opening_hours: Record<string, Day> | null;
    version: number;
};
const days: Record<string, string> = { '1': 'Lundi', '2': 'Mardi', '3': 'Mercredi', '4': 'Jeudi', '5': 'Vendredi', '6': 'Samedi', '7': 'Dimanche' };
/** Édite les paramètres de clinique et les horaires avec contrôle de version et justification. */
export default function Settings({
    setting,
    events,
    timezone,
}: {
    setting: Setting;
    events: {
        data: { id: number; actor_name: string; version: number; reason: string; created_at: string; snapshot: Setting }[];
        current_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    timezone: string;
}) {
    const { status } = usePage<SharedData>().props;
    const form = useForm({
        name: setting.name,
        phone: setting.phone ?? '',
        email: setting.email ?? '',
        address: setting.address ?? '',
        currency: setting.currency,
        enforce_hours: Boolean(Object.keys(setting.opening_hours ?? {}).length),
        opening_hours: Object.fromEntries(
            Object.keys(days).map((day) => [day, setting.opening_hours?.[day] ?? { enabled: false, opens: '08:00', closes: '17:00' }]),
        ),
        version: setting.version,
        reason: '',
    });
    const hoursError = form.errors as Record<string, string | undefined>;
    const setDay = (day: string, values: Partial<Day>) =>
        form.setData('opening_hours', { ...form.data.opening_hours, [day]: { ...form.data.opening_hours[day], ...values } });
    return (
        <AppLayout breadcrumbs={[{ title: 'Paramètres de l’établissement', href: '/administration/settings' }]}>
            <Head title="Paramètres de l’établissement" />
            <main className="flex flex-col gap-6 p-6">
                <h1 className="text-3xl font-semibold">Paramètres de l’établissement</h1>
                {status && (
                    <p role="status" className="rounded-lg border p-4">
                        {status}
                    </p>
                )}
                <form
                    className="space-y-6 rounded-xl border p-6"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.patch('/administration/settings', {
                            preserveScroll: true,
                            onSuccess: () => form.setData((data) => ({ ...data, version: data.version + 1, reason: '' })),
                        });
                    }}
                >
                    <InputError message={form.errors.version} />
                    <div className="grid gap-4 md:grid-cols-2">
                        {(
                            [
                                { key: 'name', label: 'Nom de l’établissement', max: 255 },
                                { key: 'phone', label: 'Téléphone', max: 30 },
                                { key: 'email', label: 'Adresse e-mail', max: 255 },
                                { key: 'address', label: 'Adresse', max: 500 },
                            ] as const
                        ).map(({ key, label, max }) => (
                            <div key={key}>
                                <label htmlFor={key} className="mb-2 block text-sm">
                                    {label}
                                </label>
                                <Input
                                    id={key}
                                    type={key === 'email' ? 'email' : 'text'}
                                    required={key === 'name'}
                                    maxLength={max}
                                    value={form.data[key]}
                                    onChange={(event) => form.setData(key, event.target.value)}
                                />
                                <InputError message={form.errors[key]} />
                            </div>
                        ))}
                    </div>
                    <div>
                        <label htmlFor="currency" className="mb-2 block text-sm">
                            Devise des nouveaux actes et factures
                        </label>
                        <select
                            id="currency"
                            className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                            value={form.data.currency}
                            onChange={(event) => form.setData('currency', event.target.value)}
                        >
                            {['XOF', 'EUR', 'USD'].map((value) => (
                                <option key={value} value={value}>
                                    {value}
                                </option>
                            ))}
                        </select>
                        <InputError message={form.errors.currency} />
                        <p className="text-muted-foreground mt-2 text-sm">
                            Les documents existants gardent leur devise. Les nouveaux actes devront correspondre à la nouvelle devise choisie.
                        </p>
                    </div>
                    <fieldset className="space-y-4">
                        <legend className="font-semibold">Horaires d’ouverture · {timezone}</legend>
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                checked={form.data.enforce_hours}
                                onChange={(event) => form.setData('enforce_hours', event.target.checked)}
                            />
                            Limiter les nouveaux rendez-vous et disponibilités à ces horaires
                        </label>
                        <InputError message={form.errors.opening_hours} />
                        {Object.entries(days).map(([day, label]) => (
                            <div className="grid items-center gap-3 rounded-md border p-3 sm:grid-cols-3" key={day}>
                                <label className="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        disabled={!form.data.enforce_hours}
                                        checked={form.data.opening_hours[day].enabled}
                                        onChange={(event) => setDay(day, { enabled: event.target.checked })}
                                    />
                                    {label}
                                </label>
                                <div>
                                    <label className="mb-1 block text-sm" htmlFor={`opens_${day}`}>
                                        Ouverture
                                    </label>
                                    <Input
                                        id={`opens_${day}`}
                                        type="time"
                                        disabled={!form.data.enforce_hours || !form.data.opening_hours[day].enabled}
                                        value={form.data.opening_hours[day].opens}
                                        onChange={(event) => setDay(day, { opens: event.target.value })}
                                    />
                                    <InputError message={hoursError[`opening_hours.${day}.opens`]} />
                                </div>
                                <div>
                                    <label className="mb-1 block text-sm" htmlFor={`closes_${day}`}>
                                        Fermeture
                                    </label>
                                    <Input
                                        id={`closes_${day}`}
                                        type="time"
                                        disabled={!form.data.enforce_hours || !form.data.opening_hours[day].enabled}
                                        value={form.data.opening_hours[day].closes}
                                        onChange={(event) => setDay(day, { closes: event.target.value })}
                                    />
                                    <InputError message={hoursError[`opening_hours.${day}.closes`]} />
                                </div>
                            </div>
                        ))}
                        <p className="text-muted-foreground text-sm">
                            Aucun rendez-vous n’est déplacé automatiquement. Des horaires excluant un rendez-vous actif sont refusés. Le fuseau est
                            configuré au déploiement.
                        </p>
                    </fieldset>
                    <div>
                        <label htmlFor="reason" className="mb-2 block text-sm">
                            Motif de modification
                        </label>
                        <Input
                            id="reason"
                            required
                            maxLength={1000}
                            value={form.data.reason}
                            onChange={(event) => form.setData('reason', event.target.value)}
                        />
                        <InputError message={form.errors.reason} />
                    </div>
                    <Button disabled={form.processing}>Enregistrer les paramètres</Button>
                </form>
                <section className="rounded-xl border p-6">
                    <h2 className="font-semibold">Historique des paramètres</h2>
                    <div className="mt-4 space-y-3">
                        {events.data.map((event) => (
                            <details key={event.id} className="rounded-md border p-4">
                                <summary className="cursor-pointer">
                                    Version {event.version} · {event.actor_name} ·{' '}
                                    {new Date(event.created_at).toLocaleString('fr-FR', { timeZone: timezone })}
                                </summary>
                                <p className="mt-3 break-words">{event.reason}</p>
                                <p className="mt-2 break-words">
                                    {event.snapshot.name} · {event.snapshot.currency}
                                    <br />
                                    {event.snapshot.address}
                                    <br />
                                    {event.snapshot.email} · {event.snapshot.phone}
                                </p>
                                <ul className="mt-3 space-y-1">
                                    {Object.entries(event.snapshot.opening_hours ?? {}).map(([day, rule]) => (
                                        <li key={day}>
                                            {days[day]} : {rule.enabled ? `${rule.opens}–${rule.closes}` : 'Fermé'}
                                        </li>
                                    ))}
                                </ul>
                                {!Object.keys(event.snapshot.opening_hours ?? {}).length && (
                                    <p className="mt-2">Horaires de réservation non restreints.</p>
                                )}
                            </details>
                        ))}
                    </div>
                    {!events.data.length && <p className="text-muted-foreground mt-3">Aucune modification enregistrée.</p>}
                    <nav aria-label="Historique des paramètres" className="mt-4 flex gap-4">
                        {events.prev_page_url && <Link href={events.prev_page_url}>Plus récent</Link>}
                        <span>Page {events.current_page}</span>
                        {events.next_page_url && <Link href={events.next_page_url}>Plus ancien</Link>}
                    </nav>
                </section>
            </main>
        </AppLayout>
    );
}
