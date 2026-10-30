import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';

/** Archive ou réactive une fiche avec motif et confirmation explicite. */
export function PatientLifecycle({ patientId, archived }: { patientId: number; archived: boolean }) {
    const form = useForm({ archived: !archived, reason: '' });
    function submit(event: FormEvent) {
        event.preventDefault();
        if (!window.confirm(archived ? 'Réactiver cette fiche patient ?' : 'Archiver cette fiche sans supprimer ses historiques ?')) return;
        form.patch(`/patients/${patientId}/lifecycle`, { preserveScroll: true, onSuccess: () => form.reset('reason') });
    }
    return (
        <form onSubmit={submit} className="space-y-3 rounded-xl border p-6">
            <h2 className="font-semibold">{archived ? 'Réactiver le patient' : 'Archiver le patient'}</h2>
            <p>
                Les données et historiques sont conservés. L’archivage bloque les nouvelles opérations et exige de clôturer les rendez-vous et
                factures ouverts.
            </p>
            <label htmlFor="lifecycle_reason" className="block">
                Motif administratif
            </label>
            <textarea
                id="lifecycle_reason"
                required
                minLength={5}
                maxLength={1000}
                className="w-full rounded-md border p-2"
                value={form.data.reason}
                onChange={(event) => form.setData('reason', event.target.value)}
            />
            <InputError message={form.errors.reason} />
            <Button disabled={form.processing}>{archived ? 'Réactiver le patient' : 'Archiver le patient'}</Button>
        </form>
    );
}
