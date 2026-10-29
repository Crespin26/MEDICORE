import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';

type Doctor = { id: number; name: string };
export type Share = { id: number; viewer_id: number; created_at: string };
function Revoke({ patientId, share }: { patientId: number; share: Share }) {
    const form = useForm({ reason: '' });
    return (
        <form
            className="mt-3 space-y-2"
            onSubmit={(event) => {
                event.preventDefault();
                form.patch(`/patients/${patientId}/clinical-shares/${share.id}/revoke`, { preserveScroll: true });
            }}
        >
            <label htmlFor={`revoke_${share.id}`}>Motif de révocation</label>
            <input
                id={`revoke_${share.id}`}
                className="w-full rounded-md border p-2"
                required
                minLength={5}
                maxLength={1000}
                value={form.data.reason}
                onChange={(event) => form.setData('reason', event.target.value)}
            />
            <InputError message={form.errors.reason} />
            <Button variant="outline" disabled={form.processing}>
                Révoquer le partage
            </Button>
        </form>
    );
}
export function ClinicalSharing({
    patientId,
    doctors,
    shares,
    incoming,
}: {
    patientId: number;
    doctors: Doctor[];
    shares: Share[];
    incoming: Doctor[];
}) {
    const form = useForm({ viewer_id: '', reason: '' });
    function submit(event: FormEvent) {
        event.preventDefault();
        if (!window.confirm('Partager en lecture seule vos consultations, révisions, informations et documents de ce patient, existants et futurs ?'))
            return;
        form.post(`/patients/${patientId}/clinical-shares`, { preserveScroll: true, onSuccess: () => form.reset() });
    }
    return (
        <section className="space-y-4 rounded-xl border p-6">
            <h2 className="text-lg font-semibold">Partage entre médecins</h2>
            <p>
                Vous accordez la lecture de vos éléments existants et futurs pour ce patient. Seul l’auteur peut les corriger. La révocation bloque
                les prochains accès ; elle ne retire pas les documents déjà téléchargés.
            </p>
            {incoming.length > 0 && <p>Éléments partagés avec vous par : {incoming.map((doctor) => doctor.name).join(', ')}.</p>}
            <form onSubmit={submit} className="space-y-3">
                <label htmlFor="sharing_doctor" className="block">
                    Médecin destinataire
                </label>
                <select
                    id="sharing_doctor"
                    required
                    className="w-full rounded-md border p-2"
                    value={form.data.viewer_id}
                    onChange={(event) => form.setData('viewer_id', event.target.value)}
                >
                    <option value="">Choisir un autre médecin actif</option>
                    {doctors.map((doctor) => (
                        <option key={doctor.id} value={doctor.id}>
                            {doctor.name}
                        </option>
                    ))}
                </select>
                <InputError message={form.errors.viewer_id} />
                <label htmlFor="sharing_reason" className="block">
                    Motif du partage
                </label>
                <textarea
                    id="sharing_reason"
                    required
                    minLength={5}
                    maxLength={1000}
                    className="w-full rounded-md border p-2"
                    value={form.data.reason}
                    onChange={(event) => form.setData('reason', event.target.value)}
                />
                <InputError message={form.errors.reason} />
                <Button disabled={form.processing || doctors.length === 0}>Accorder la lecture</Button>
            </form>
            <ul className="space-y-3">
                {shares.map((share) => (
                    <li key={share.id} className="rounded-md border p-3">
                        Partage accordé à {doctors.find((doctor) => doctor.id === share.viewer_id)?.name ?? `Médecin n° ${share.viewer_id}`}.
                        <Revoke key={share.id} patientId={patientId} share={share} />
                    </li>
                ))}
            </ul>
        </section>
    );
}
