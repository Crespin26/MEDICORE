import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';
export type Entry = { id: number; kind: string; label: string; details: string | null; occurred_on: string | null; status: string; version: number };
export const inputClass = 'border-input bg-background w-full rounded-md border px-3 py-2 text-sm';
export function EntryForm({
    patientId,
    kinds,
    entry,
    onCancel,
}: {
    patientId: number;
    kinds: Record<string, string>;
    entry?: Entry;
    onCancel?: () => void;
}) {
    const form = useForm({
        kind: entry?.kind ?? 'history',
        label: entry?.label ?? '',
        details: entry?.details ?? '',
        occurred_on: entry?.occurred_on ?? '',
        status: entry?.status ?? 'active',
        version: entry?.version ?? 1,
        correction_reason: '',
    });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => onCancel?.() };
        if (entry) form.patch(`/patients/${patientId}/medical-entries/${entry.id}`, options);
        else form.post(`/patients/${patientId}/medical-entries`, options);
    };
    return (
        <form onSubmit={submit} className="space-y-4 rounded-xl border p-6">
            <h2 className="text-lg font-semibold">{entry ? 'Corriger l’information' : 'Ajouter une information médicale'}</h2>
            <InputError message={form.errors.version} />
            <div className="grid gap-4 md:grid-cols-2">
                <div>
                    <label className="mb-2 block text-sm" htmlFor="kind">
                        Catégorie
                    </label>
                    <select id="kind" className={inputClass} value={form.data.kind} onChange={(event) => form.setData('kind', event.target.value)}>
                        {Object.entries(kinds).map(([value, label]) => (
                            <option value={value} key={value}>
                                {label}
                            </option>
                        ))}
                    </select>
                    <InputError message={form.errors.kind} />
                </div>
                <div>
                    <label className="mb-2 block text-sm" htmlFor="entry_status">
                        État
                    </label>
                    <select
                        id="entry_status"
                        className={inputClass}
                        value={form.data.status}
                        onChange={(event) => form.setData('status', event.target.value)}
                    >
                        <option value="active">Actif</option>
                        <option value="resolved">Résolu / terminé</option>
                    </select>
                    <InputError message={form.errors.status} />
                </div>
            </div>
            <div>
                <label className="mb-2 block text-sm" htmlFor="label">
                    Libellé
                </label>
                <Input id="label" required maxLength={500} value={form.data.label} onChange={(event) => form.setData('label', event.target.value)} />
                <InputError message={form.errors.label} />
            </div>
            <div>
                <label className="mb-2 block text-sm" htmlFor="occurred_on">
                    Date de survenue ou de début (facultative)
                </label>
                <Input
                    id="occurred_on"
                    type="date"
                    value={form.data.occurred_on}
                    onChange={(event) => form.setData('occurred_on', event.target.value)}
                />
                <InputError message={form.errors.occurred_on} />
            </div>
            <div>
                <label className="mb-2 block text-sm" htmlFor="details">
                    Détails (facultatifs)
                </label>
                <textarea
                    id="details"
                    className={inputClass}
                    rows={5}
                    maxLength={10000}
                    value={form.data.details}
                    onChange={(event) => form.setData('details', event.target.value)}
                />
                <InputError message={form.errors.details} />
            </div>
            {entry && (
                <div>
                    <label className="mb-2 block text-sm" htmlFor="correction_reason">
                        Motif de correction
                    </label>
                    <textarea
                        id="correction_reason"
                        className={inputClass}
                        rows={3}
                        required
                        maxLength={1000}
                        value={form.data.correction_reason}
                        onChange={(event) => form.setData('correction_reason', event.target.value)}
                    />
                    <InputError message={form.errors.correction_reason} />
                </div>
            )}
            <div className="flex gap-3">
                <Button disabled={form.processing}>{entry ? 'Enregistrer la correction' : 'Enregistrer'}</Button>
                {onCancel && (
                    <Button type="button" variant="outline" disabled={form.processing} onClick={onCancel}>
                        Annuler les modifications
                    </Button>
                )}
            </div>
        </form>
    );
}
export function displayTime(value: string, timezone: string): string {
    return new Date(value.includes('T') ? value : `${value.replace(' ', 'T')}Z`).toLocaleString('fr-FR', { timeZone: timezone });
}
export type Page<T> = { data: T[]; current_page: number; total: number; prev_page_url: string | null; next_page_url: string | null };
