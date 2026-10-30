import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useForm } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';
export const vitalFields = [
    { key: 'temperature', label: 'Température (°C)', step: '0.1' },
    { key: 'pulse', label: 'Pouls (battements/min)', step: '1' },
    { key: 'systolic', label: 'Pression systolique (mmHg)', step: '1' },
    { key: 'diastolic', label: 'Pression diastolique (mmHg)', step: '1' },
    { key: 'oxygen_saturation', label: 'Saturation en oxygène (%)', step: '0.1' },
    { key: 'weight', label: 'Poids (kg)', step: '0.1' },
    { key: 'height', label: 'Taille (cm)', step: '0.1' },
] as const;
export type VitalKey = (typeof vitalFields)[number]['key'];
export type Vitals = Partial<Record<VitalKey, string | number | null>>;
export type Consultation = {
    id: number;
    patient_id: number;
    appointment_id: number | null;
    consulted_at: string;
    reason: string;
    observations: string | null;
    report: string | null;
    vitals: Vitals | null;
    status: 'draft' | 'validated';
    version: number;
    validated_at: string | null;
    patient: { id: number; first_name: string; last_name: string };
    doctor: { id: number; name: string };
};
const textareaClass = 'border-input bg-background w-full rounded-md border p-3 text-sm';
/** Saisit une consultation en heure clinique avec version et motif lors d’une correction. */
export function ConsultationForm({
    patientId,
    now,
    appointmentId = null,
    consultation,
    onCancel,
}: {
    patientId: number;
    now: string;
    appointmentId?: number | null;
    consultation?: Consultation;
    onCancel?: () => void;
}) {
    const validated = consultation?.status === 'validated';
    const [action, setAction] = useState<'draft' | 'validate'>(validated ? 'validate' : 'draft');
    const initialVitals = Object.fromEntries(vitalFields.map(({ key }) => [key, String(consultation?.vitals?.[key] ?? '')])) as Record<
        VitalKey,
        string
    >;
    const form = useForm({
        consulted_at: consultation?.consulted_at ?? now,
        reason: consultation?.reason ?? '',
        observations: consultation?.observations ?? '',
        report: consultation?.report ?? '',
        vitals: initialVitals,
        version: consultation?.version ?? 1,
        appointment_id: consultation?.appointment_id ?? appointmentId,
        correction_reason: '',
    });
    const vitalErrors = form.errors as Partial<Record<`vitals.${VitalKey}`, string>>;
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (
            action === 'validate' &&
            !window.confirm(
                validated
                    ? 'Enregistrer cette correction avec son motif dans l’historique ?'
                    : 'Valider ce compte rendu ? Les modifications ultérieures devront être justifiées.',
            )
        )
            return;
        form.transform((data) => ({ ...data, action }));
        const options = { preserveScroll: true, onSuccess: () => onCancel?.() };
        if (consultation) form.patch(`/consultations/${consultation.id}`, options);
        else form.post(`/patients/${patientId}/consultations`, options);
    };
    return (
        <form onSubmit={submit} className="flex flex-col gap-6 rounded-xl border p-6">
            <h2 className="text-lg font-semibold">{validated ? 'Corriger le compte rendu validé' : 'Compte rendu de consultation'}</h2>
            <InputError message={form.errors.version} />
            <InputError message={form.errors.appointment_id} />
            <div>
                <label htmlFor="consulted_at" className="mb-2 block text-sm">
                    Date et heure de consultation
                </label>
                <Input
                    id="consulted_at"
                    type="datetime-local"
                    required
                    value={form.data.consulted_at}
                    onChange={(event) => form.setData('consulted_at', event.target.value)}
                />
                <InputError message={form.errors.consulted_at} />
            </div>
            <div>
                <label htmlFor="reason" className="mb-2 block text-sm">
                    Motif
                </label>
                <Input
                    id="reason"
                    required
                    maxLength={500}
                    value={form.data.reason}
                    onChange={(event) => form.setData('reason', event.target.value)}
                />
                <InputError message={form.errors.reason} />
            </div>
            <fieldset>
                <legend className="mb-3 font-semibold">Constantes (facultatives)</legend>
                <InputError message={form.errors.vitals} />
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {vitalFields.map(({ key, label, step }) => (
                        <div key={key}>
                            <label htmlFor={key} className="mb-2 block text-sm">
                                {label}
                            </label>
                            <Input
                                id={key}
                                type="number"
                                step={step}
                                min={0}
                                max={key === 'oxygen_saturation' ? 100 : 9999}
                                value={form.data.vitals[key]}
                                onChange={(event) => form.setData('vitals', { ...form.data.vitals, [key]: event.target.value })}
                            />
                            <InputError message={vitalErrors[`vitals.${key}`]} />
                        </div>
                    ))}
                </div>
            </fieldset>
            <div>
                <label htmlFor="observations" className="mb-2 block text-sm">
                    Observations
                </label>
                <textarea
                    id="observations"
                    className={textareaClass}
                    rows={5}
                    maxLength={20000}
                    value={form.data.observations}
                    onChange={(event) => form.setData('observations', event.target.value)}
                />
                <InputError message={form.errors.observations} />
            </div>
            <div>
                <label htmlFor="report" className="mb-2 block text-sm">
                    Compte rendu {action === 'validate' && '(requis pour valider)'}
                </label>
                <textarea
                    id="report"
                    className={textareaClass}
                    rows={8}
                    maxLength={30000}
                    required={action === 'validate'}
                    value={form.data.report}
                    onChange={(event) => form.setData('report', event.target.value)}
                />
                <InputError message={form.errors.report} />
            </div>
            {!validated && (
                <div>
                    <label htmlFor="action" className="mb-2 block text-sm">
                        Enregistrement
                    </label>
                    <select
                        id="action"
                        className={textareaClass}
                        value={action}
                        onChange={(event) => setAction(event.target.value as 'draft' | 'validate')}
                    >
                        <option value="draft">Conserver en brouillon</option>
                        <option value="validate">Valider le compte rendu</option>
                    </select>
                </div>
            )}
            {validated && (
                <div>
                    <label htmlFor="correction_reason" className="mb-2 block text-sm">
                        Motif de la correction
                    </label>
                    <textarea
                        id="correction_reason"
                        className={textareaClass}
                        required
                        maxLength={1000}
                        rows={3}
                        value={form.data.correction_reason}
                        onChange={(event) => form.setData('correction_reason', event.target.value)}
                    />
                    <InputError message={form.errors.correction_reason} />
                </div>
            )}
            <div className="flex flex-wrap gap-3">
                <Button disabled={form.processing}>
                    {validated ? 'Enregistrer la correction' : action === 'validate' ? 'Valider le compte rendu' : 'Enregistrer le brouillon'}
                </Button>
                {onCancel && (
                    <Button type="button" variant="outline" disabled={form.processing} onClick={onCancel}>
                        Annuler les modifications
                    </Button>
                )}
            </div>
        </form>
    );
}
