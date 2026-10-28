import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useForm } from '@inertiajs/react';
import { useState } from 'react';
export type Profile = {
    id: number;
    user_id: number;
    employee_number: string | null;
    job_title: string;
    department: string | null;
    specialties: string[] | null;
    professional_phone: string | null;
    license_number: string | null;
    started_on: string | null;
    version: number;
};
export const selectClass = 'border-input bg-background w-full rounded-md border px-3 py-2 text-sm';
export function ProfileForm({ userId, profile }: { userId: number; profile: Profile | null }) {
    const form = useForm({
        employee_number: profile?.employee_number ?? '',
        job_title: profile?.job_title ?? '',
        department: profile?.department ?? '',
        specialties: profile?.specialties ?? [],
        professional_phone: profile?.professional_phone ?? '',
        license_number: profile?.license_number ?? '',
        started_on: profile?.started_on ?? '',
        version: profile?.version ?? 0,
        reason: '',
    });
    const [specialties, setSpecialties] = useState(profile?.specialties?.join(', ') ?? '');
    const fields = [
        { key: 'employee_number', label: 'Matricule (majuscules, chiffres, tirets)', max: 40 },
        { key: 'job_title', label: 'Fonction professionnelle', max: 150 },
        { key: 'department', label: 'Service / département', max: 150 },
        { key: 'professional_phone', label: 'Téléphone professionnel', max: 30 },
        { key: 'license_number', label: 'Identifiant professionnel déclaré', max: 100 },
    ] as const;
    return (
        <form
            className="space-y-4 rounded-xl border p-6"
            onSubmit={(event) => {
                event.preventDefault();
                form.patch(`/personnel/${userId}`, {
                    preserveScroll: true,
                    onSuccess: () => form.setData((data) => ({ ...data, version: data.version + 1, reason: '' })),
                });
            }}
        >
            <h2 className="text-lg font-semibold">{profile ? 'Modifier la fiche professionnelle' : 'Compléter la fiche professionnelle'}</h2>
            <p className="text-muted-foreground text-sm">
                La fonction et les spécialités n’attribuent aucun droit d’accès. Les rôles se gèrent dans les comptes du personnel.
            </p>
            <InputError message={form.errors.version} />
            <div className="grid gap-4 md:grid-cols-2">
                {fields.map(({ key, label, max }) => (
                    <div key={key}>
                        <label htmlFor={key} className="mb-2 block text-sm">
                            {label}
                        </label>
                        <Input
                            id={key}
                            maxLength={max}
                            required={key === 'job_title'}
                            value={form.data[key]}
                            onChange={(event) => form.setData(key, event.target.value)}
                        />
                        <InputError message={form.errors[key]} />
                    </div>
                ))}
                <div>
                    <label htmlFor="specialties" className="mb-2 block text-sm">
                        Spécialités (séparées par des virgules, 10 maximum)
                    </label>
                    <Input
                        id="specialties"
                        maxLength={1100}
                        value={specialties}
                        onChange={(event) => {
                            setSpecialties(event.target.value);
                            form.setData(
                                'specialties',
                                event.target.value
                                    .split(',')
                                    .map((value) => value.trim())
                                    .filter(Boolean),
                            );
                        }}
                    />
                    <InputError message={form.errors.specialties} />
                    {Object.entries(form.errors)
                        .filter(([key]) => key.startsWith('specialties.'))
                        .map(([key, message]) => (
                            <InputError key={key} message={message} />
                        ))}
                </div>
                <div>
                    <label htmlFor="started_on" className="mb-2 block text-sm">
                        Date d’entrée (facultative)
                    </label>
                    <Input
                        id="started_on"
                        type="date"
                        value={form.data.started_on}
                        onChange={(event) => form.setData('started_on', event.target.value)}
                    />
                    <InputError message={form.errors.started_on} />
                </div>
            </div>
            <div>
                <label htmlFor="profile_reason" className="mb-2 block text-sm">
                    Motif de l’enregistrement
                </label>
                <Input
                    id="profile_reason"
                    maxLength={1000}
                    required
                    value={form.data.reason}
                    onChange={(event) => form.setData('reason', event.target.value)}
                />
                <InputError message={form.errors.reason} />
            </div>
            <Button disabled={form.processing}>Enregistrer la fiche</Button>
        </form>
    );
}
