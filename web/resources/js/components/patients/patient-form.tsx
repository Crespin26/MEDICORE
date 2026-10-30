import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { type SharedData } from '@/types';
import { useForm, usePage } from '@inertiajs/react';
import { type FormEvent } from 'react';

export type Patient = {
    id: number;
    first_name: string;
    last_name: string;
    birth_date: string;
    phone: string | null;
    email: string | null;
    address: string | null;
    archived_at?: string | null;
    distinct_identity?: boolean;
    created_at?: string;
    updated_at?: string;
};

/** Crée ou corrige une identité et expose les erreurs métier, dont les homonymes à confirmer. */
export function PatientForm({ patient, onDone }: { patient?: Patient; onDone?: () => void }) {
    const { auth } = usePage<SharedData>().props;
    const form = useForm({
        first_name: patient?.first_name ?? '',
        last_name: patient?.last_name ?? '',
        birth_date: patient?.birth_date ?? '',
        phone: patient?.phone ?? '',
        email: patient?.email ?? '',
        address: patient?.address ?? '',
        confirm_distinct: false as boolean,
        distinct_reason: '',
    });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onDone?.();
            },
        };
        if (patient) form.patch(`/patients/${patient.id}`, options);
        else form.post('/patients', options);
    };
    const fields = [
        { key: 'first_name', label: 'Prénom', type: 'text', max: 100 },
        { key: 'last_name', label: 'Nom', type: 'text', max: 100 },
        { key: 'birth_date', label: 'Date de naissance', type: 'date', max: undefined },
        { key: 'phone', label: 'Téléphone (facultatif)', type: 'tel', max: 30 },
        { key: 'email', label: 'E-mail (facultatif)', type: 'email', max: 255 },
        { key: 'address', label: 'Adresse (facultatif)', type: 'text', max: 500 },
    ] as const;
    return (
        <form onSubmit={submit} className="rounded-xl border p-6">
            <h2 className="mb-4 text-lg font-semibold">{patient ? 'Modifier la fiche patient' : 'Nouveau patient'}</h2>
            <div className="grid gap-4 md:grid-cols-2">
                {fields.map(({ key, label, type, max }) => (
                    <div key={key}>
                        <label htmlFor={key} className="mb-2 block text-sm">
                            {label}
                        </label>
                        <Input
                            id={key}
                            type={type}
                            value={form.data[key]}
                            required={['first_name', 'last_name', 'birth_date'].includes(key)}
                            maxLength={max}
                            onChange={(event) => form.setData(key, event.target.value)}
                        />
                        <InputError message={form.errors[key]} />
                    </div>
                ))}
            </div>
            {auth.user.role === 'admin' && (
                <fieldset className="mt-4 space-y-3 rounded-md border p-3">
                    <legend>Homonymes — confirmation administrative</legend>
                    <label className="flex gap-2">
                        <input
                            type="checkbox"
                            checked={form.data.confirm_distinct}
                            onChange={(event) => form.setData('confirm_distinct', event.target.checked)}
                        />
                        Je confirme qu’il s’agit d’une personne distincte malgré une identité identique.
                    </label>
                    {form.data.confirm_distinct && (
                        <>
                            <label htmlFor="distinct_reason" className="block">
                                Justification de la distinction
                            </label>
                            <textarea
                                id="distinct_reason"
                                required
                                minLength={5}
                                maxLength={1000}
                                className="w-full rounded-md border p-2"
                                value={form.data.distinct_reason}
                                onChange={(event) => form.setData('distinct_reason', event.target.value)}
                            />
                        </>
                    )}
                    <InputError message={form.errors.distinct_reason} />
                </fieldset>
            )}
            <div className="mt-4 flex gap-3">
                <Button disabled={form.processing}>{patient ? 'Enregistrer les modifications' : 'Créer le patient'}</Button>
                {patient && onDone && (
                    <Button variant="outline" type="button" disabled={form.processing} onClick={onDone}>
                        Annuler
                    </Button>
                )}
            </div>
        </form>
    );
}
