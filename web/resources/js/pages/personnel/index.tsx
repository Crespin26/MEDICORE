import InputError from '@/components/input-error';
import { selectClass, type Profile } from '@/components/personnel/profile-form';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
export default function Personnel({
    staff,
    filters,
    roles,
}: {
    staff: {
        data: { id: number; name: string; email: string; role: string; status: string; profile: Profile | null }[];
        total: number;
        current_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: { search: string; role: string; status: string };
    roles: Record<string, string>;
}) {
    const form = useForm(filters);
    const statuses: Record<string, string> = { active: 'Actif', pending: 'Invitation en attente', suspended: 'Suspendu' };
    return (
        <AppLayout breadcrumbs={[{ title: 'Personnel', href: '/personnel' }]}>
            <Head title="Médecins et personnel" />
            <main className="flex flex-col gap-6 p-6">
                <div className="flex flex-wrap justify-between gap-4">
                    <h1 className="text-3xl font-semibold">Médecins et personnel</h1>
                    <Link className="underline" href="/staff">
                        Invitations, comptes et rôles
                    </Link>
                </div>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.get('/personnel');
                    }}
                    className="grid items-end gap-4 rounded-xl border p-4 md:grid-cols-4"
                >
                    <div>
                        <label htmlFor="search" className="mb-2 block text-sm">
                            Nom, e-mail, matricule, fonction ou service
                        </label>
                        <Input
                            id="search"
                            type="search"
                            maxLength={100}
                            value={form.data.search}
                            onChange={(event) => form.setData('search', event.target.value)}
                        />
                        <InputError message={form.errors.search} />
                    </div>
                    <div>
                        <label htmlFor="role" className="mb-2 block text-sm">
                            Rôle
                        </label>
                        <select
                            id="role"
                            className={selectClass}
                            value={form.data.role}
                            onChange={(event) => form.setData('role', event.target.value)}
                        >
                            <option value="">Tous</option>
                            {Object.entries(roles).map(([value, label]) => (
                                <option key={value} value={value}>
                                    {label}
                                </option>
                            ))}
                        </select>
                        <InputError message={form.errors.role} />
                    </div>
                    <div>
                        <label htmlFor="status" className="mb-2 block text-sm">
                            Statut du compte
                        </label>
                        <select
                            id="status"
                            className={selectClass}
                            value={form.data.status}
                            onChange={(event) => form.setData('status', event.target.value)}
                        >
                            <option value="">Tous</option>
                            {Object.entries(statuses).map(([value, label]) => (
                                <option key={value} value={value}>
                                    {label}
                                </option>
                            ))}
                        </select>
                        <InputError message={form.errors.status} />
                    </div>
                    <Button disabled={form.processing}>Rechercher</Button>
                </form>
                <Link className="underline" href="/personnel">
                    Réinitialiser les filtres
                </Link>
                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-left text-sm">
                        <caption className="p-4 text-left font-semibold">{staff.total} membre(s)</caption>
                        <thead>
                            <tr className="border-b">
                                {['Membre', 'Rôle / compte', 'Fonction', 'Spécialités', 'Action'].map((label) => (
                                    <th className="p-4" key={label}>
                                        {label}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {staff.data.map((member) => (
                                <tr key={member.id} className="border-b">
                                    <td className="p-4">
                                        {member.name}
                                        <p className="text-muted-foreground mt-1">{member.email}</p>
                                    </td>
                                    <td className="p-4">
                                        {roles[member.role] ?? 'Rôle non attribué'} · {statuses[member.status]}
                                    </td>
                                    <td className="p-4">
                                        {member.profile?.job_title ?? 'Fiche à compléter'}
                                        <p className="text-muted-foreground mt-1">
                                            {member.profile?.department} {member.profile?.employee_number}
                                        </p>
                                    </td>
                                    <td className="p-4">{member.profile?.specialties?.join(', ') || 'Non renseignées'}</td>
                                    <td className="p-4">
                                        <Link className="underline" href={`/personnel/${member.id}`}>
                                            Ouvrir la fiche
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                            {!staff.data.length && (
                                <tr>
                                    <td colSpan={5} className="p-6 text-center">
                                        Aucun membre pour cette sélection.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
                <nav aria-label="Personnel" className="flex gap-4">
                    {staff.prev_page_url && <Link href={staff.prev_page_url}>Précédent</Link>}
                    <span>Page {staff.current_page}</span>
                    {staff.next_page_url && <Link href={staff.next_page_url}>Suivant</Link>}
                </nav>
            </main>
        </AppLayout>
    );
}
