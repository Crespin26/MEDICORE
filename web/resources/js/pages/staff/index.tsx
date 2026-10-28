import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { type FormEvent } from 'react';

type Role = { value: string; label: string };
type Account = { id: number; name: string; email: string; role: string; status: string };
type Page = { data: Account[]; current_page: number; prev_page_url: string | null; next_page_url: string | null };
const statuses: Record<string, string> = { active: 'Actif', pending: 'Invitation en attente', suspended: 'Suspendu' };
const selectClass = 'border-input bg-background rounded-md border px-3 py-2 text-sm';

function AccountRow({ account, roles, self }: { account: Account; roles: Role[]; self: boolean }) {
    const access = useForm({ role: account.role, status: account.status });
    const invitation = useForm({});
    const save = () => {
        if (window.confirm(`Appliquer ces accès au compte de ${account.name} ?`)) access.patch(`/staff/${account.id}`);
    };
    return (
        <tr className="border-b">
            <td className="p-4">
                <p className="font-medium">
                    {account.name}
                    {self && ' (vous)'}
                </p>
                <p className="text-muted-foreground">{account.email}</p>
            </td>
            <td className="p-4">
                <select
                    aria-label={`Rôle de ${account.name}`}
                    className={selectClass}
                    value={access.data.role}
                    disabled={self || account.status === 'pending' || access.processing}
                    onChange={(e) => access.setData('role', e.target.value)}
                >
                    {!roles.some((role) => role.value === account.role) && <option value={account.role}>Ancien rôle non attribué</option>}
                    {roles.map((role) => (
                        <option key={role.value} value={role.value}>
                            {role.label}
                        </option>
                    ))}
                </select>
                <InputError message={access.errors.role} />
            </td>
            <td className="p-4">
                {account.status === 'pending' ? (
                    statuses.pending
                ) : (
                    <select
                        aria-label={`Statut de ${account.name}`}
                        className={selectClass}
                        value={access.data.status}
                        disabled={self || access.processing}
                        onChange={(e) => access.setData('status', e.target.value)}
                    >
                        <option value="active">Actif</option>
                        <option value="suspended">Suspendu</option>
                    </select>
                )}
                <InputError message={access.errors.status} />
            </td>
            <td className="p-4">
                {account.status === 'pending' ? (
                    <Button variant="outline" disabled={invitation.processing} onClick={() => invitation.post(`/staff/${account.id}/invitation`)}>
                        Renvoyer l’invitation
                    </Button>
                ) : (
                    !self && (
                        <Button variant="outline" disabled={access.processing} onClick={save}>
                            Enregistrer les accès
                        </Button>
                    )
                )}
            </td>
        </tr>
    );
}

export default function Staff({ staff, roles }: { staff: Page; roles: Role[] }) {
    const { auth, status } = usePage<SharedData>().props;
    const form = useForm({ name: '', email: '', role: 'secretary' });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/staff', { onSuccess: () => form.reset() });
    };
    return (
        <AppLayout breadcrumbs={[{ title: 'Comptes du personnel', href: '/staff' }]}>
            <Head title="Comptes du personnel" />
            <main className="flex flex-col gap-6 p-6">
                <div>
                    <h1 className="text-3xl font-semibold">Comptes du personnel</h1>
                    <Link className="mt-3 inline-block underline" href="/personnel">
                        Profils professionnels et absences
                    </Link>
                    <p className="text-muted-foreground mt-2">Invitez le personnel et gérez ses accès à la clinique.</p>
                </div>
                {status && (
                    <p role="status" className="rounded-lg border p-4">
                        {status}
                    </p>
                )}
                <form onSubmit={submit} className="rounded-xl border p-6">
                    <h2 className="mb-4 text-lg font-semibold">Inviter un membre</h2>
                    <div className="grid gap-4 md:grid-cols-3">
                        <div>
                            <label htmlFor="name" className="mb-2 block">
                                Nom complet
                            </label>
                            <Input id="name" value={form.data.name} maxLength={255} required onChange={(e) => form.setData('name', e.target.value)} />
                            <InputError message={form.errors.name} />
                        </div>
                        <div>
                            <label htmlFor="email" className="mb-2 block">
                                Adresse e-mail
                            </label>
                            <Input
                                id="email"
                                type="email"
                                value={form.data.email}
                                maxLength={255}
                                required
                                onChange={(e) => form.setData('email', e.target.value.toLowerCase())}
                            />
                            <InputError message={form.errors.email} />
                        </div>
                        <div>
                            <label htmlFor="role" className="mb-2 block">
                                Rôle
                            </label>
                            <select
                                id="role"
                                className={`${selectClass} w-full`}
                                value={form.data.role}
                                onChange={(e) => form.setData('role', e.target.value)}
                            >
                                {roles.map((role) => (
                                    <option key={role.value} value={role.value}>
                                        {role.label}
                                    </option>
                                ))}
                            </select>
                            <InputError message={form.errors.role} />
                        </div>
                    </div>
                    <p className="text-muted-foreground my-4 text-sm">
                        Le destinataire choisira son mot de passe avec un lien valable 48 heures. Chaque renvoi invalide le lien précédent.
                    </p>
                    <Button disabled={form.processing}>Envoyer l’invitation</Button>
                </form>
                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-left text-sm">
                        <caption className="p-4 text-left font-semibold">Personnel de la clinique</caption>
                        <thead>
                            <tr className="border-b">
                                {['Compte', 'Rôle', 'Statut', 'Action'].map((label) => (
                                    <th className="p-4" key={label}>
                                        {label}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {staff.data.map((account) => (
                                <AccountRow
                                    key={`${account.id}-${account.role}-${account.status}`}
                                    account={account}
                                    roles={roles}
                                    self={account.id === auth.user.id}
                                />
                            ))}
                        </tbody>
                    </table>
                </div>
                <nav aria-label="Pagination" className="flex gap-4">
                    {staff.prev_page_url && <Link href={staff.prev_page_url}>Précédent</Link>}
                    <span>Page {staff.current_page}</span>
                    {staff.next_page_url && <Link href={staff.next_page_url}>Suivant</Link>}
                </nav>
            </main>
        </AppLayout>
    );
}
