import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useForm } from '@inertiajs/react';
import { useEffect, useState, type FormEvent } from 'react';
export type Service = { id: number; code: string; name: string; currency: string; price_minor: number; active: boolean; version: number };
export type Line = {
    id: number;
    billable_service_id: number;
    code: string;
    label: string;
    quantity: number;
    unit_price_minor: number;
    total_minor: number;
};
export const money = (value: number, currency: string) =>
    currency === 'XOF' ? `${value} XOF` : `${Math.floor(value / 100)},${String(value % 100).padStart(2, '0')} ${currency}`;
export const major = (value: number, currency: string) =>
    currency === 'XOF' ? String(value) : `${Math.floor(value / 100)}.${String(value % 100).padStart(2, '0')}`;
export const selectClass = 'border-input bg-background w-full rounded-md border px-3 py-2 text-sm';
export type Invoice = {
    id: number;
    number: string | null;
    patient_id: number;
    patient_name: string;
    currency: string;
    status: string;
    total_minor: number;
    paid_minor: number;
    version: number;
    lines: Line[];
};
function PatientPicker({ selected, onSelect }: { selected: string; onSelect: (id: string, label: string) => void }) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<{ id: number; first_name: string; last_name: string; birth_date: string }[]>([]);
    const [status, setStatus] = useState('');
    useEffect(() => {
        const controller = new AbortController();
        if (query.trim().length < 2) {
            setResults([]);
            setStatus('');
            return;
        }
        const timer = setTimeout(async () => {
            setStatus('Recherche…');
            try {
                const response = await fetch(`/billing/patients?${new URLSearchParams({ search: query })}`, {
                    signal: controller.signal,
                    headers: { Accept: 'application/json' },
                });
                if (!response.ok) throw new Error();
                const data = await response.json();
                if (!controller.signal.aborted) {
                    setResults(data);
                    setStatus(data.length ? '' : 'Aucun patient trouvé.');
                }
            } catch {
                if (!controller.signal.aborted) setStatus('Recherche indisponible. Réessayez.');
            }
        }, 250);
        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [query]);
    return (
        <div>
            <label htmlFor="patient_search" className="mb-2 block text-sm">
                Rechercher le patient par nom ou référence MC-…
            </label>
            <Input
                id="patient_search"
                type="search"
                maxLength={100}
                value={query}
                onChange={(event) => {
                    setQuery(event.target.value);
                    onSelect('', '');
                }}
            />
            <p role="status" className="mt-2 text-sm">
                {status}
            </p>
            <ul className="mt-2 max-h-48 overflow-y-auto">
                {results.map((patient) => (
                    <li key={patient.id}>
                        <button
                            type="button"
                            className="hover:bg-muted w-full rounded-md border p-2 text-left text-sm"
                            onClick={() => {
                                onSelect(String(patient.id), `${patient.last_name} ${patient.first_name} · MC-${patient.id}`);
                                setQuery('');
                            }}
                        >
                            {patient.last_name} {patient.first_name} · MC-{patient.id} · {patient.birth_date.split('-').reverse().join('/')}
                        </button>
                    </li>
                ))}
            </ul>
            <p className="mt-2 text-sm">{selected || 'Aucun patient sélectionné.'}</p>
        </div>
    );
}
export function InvoiceForm({
    services,
    currency,
    invoice,
    onCancel,
}: {
    services: Service[];
    currency: string;
    invoice?: Invoice;
    onCancel?: () => void;
}) {
    const [patientLabel, setPatientLabel] = useState('');
    const form = useForm({
        patient_id: invoice ? String(invoice.patient_id) : '',
        token: crypto.randomUUID(),
        version: invoice?.version ?? 1,
        reason: '',
        lines: invoice
            ? invoice.lines.map((line) => ({ service_id: String(line.billable_service_id), quantity: String(line.quantity) }))
            : [{ service_id: '', quantity: '1' }],
    });
    const errors = form.errors as Record<string, string | undefined>;
    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => onCancel?.() };
        if (invoice) form.patch(`/billing/invoices/${invoice.id}`, options);
        else form.post('/billing/invoices', options);
    };
    return (
        <form onSubmit={submit} className="space-y-5 rounded-xl border p-6">
            <h2 className="text-lg font-semibold">
                {invoice ? 'Corriger le brouillon' : 'Nouvelle facture brouillon'} · {currency}
            </h2>
            {!invoice && (
                <PatientPicker
                    selected={patientLabel}
                    onSelect={(id, label) => {
                        form.setData('patient_id', id);
                        setPatientLabel(label);
                    }}
                />
            )}
            <InputError message={form.errors.patient_id} />
            <InputError message={form.errors.token} />
            <InputError message={form.errors.version} />
            <InputError message={form.errors.lines} />
            {form.data.lines.map((line, index) => (
                <div key={index} className="grid items-end gap-3 rounded-md border p-3 md:grid-cols-[1fr_8rem_auto]">
                    <div>
                        <label htmlFor={`service_${index}`} className="mb-2 block text-sm">
                            Acte
                        </label>
                        <select
                            id={`service_${index}`}
                            required
                            className={selectClass}
                            value={line.service_id}
                            onChange={(event) =>
                                form.setData(
                                    'lines',
                                    form.data.lines.map((item, at) => (at === index ? { ...item, service_id: event.target.value } : item)),
                                )
                            }
                        >
                            <option value="">Choisir un acte</option>
                            {line.service_id && !services.some((service) => String(service.id) === line.service_id) && (
                                <option value={line.service_id}>Acte indisponible — choisissez un acte actif</option>
                            )}
                            {services.map((service) => (
                                <option key={service.id} value={service.id}>
                                    {service.code} · {service.name} · {money(service.price_minor, service.currency)}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors[`lines.${index}.service_id`]} />
                    </div>
                    <div>
                        <label htmlFor={`quantity_${index}`} className="mb-2 block text-sm">
                            Quantité
                        </label>
                        <Input
                            id={`quantity_${index}`}
                            type="number"
                            min={1}
                            max={1000}
                            step={1}
                            required
                            value={line.quantity}
                            onChange={(event) =>
                                form.setData(
                                    'lines',
                                    form.data.lines.map((item, at) => (at === index ? { ...item, quantity: event.target.value } : item)),
                                )
                            }
                        />
                        <InputError message={errors[`lines.${index}.quantity`]} />
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        disabled={form.processing || form.data.lines.length === 1}
                        onClick={() =>
                            form.setData(
                                'lines',
                                form.data.lines.filter((_, at) => at !== index),
                            )
                        }
                    >
                        Retirer
                    </Button>
                </div>
            ))}
            <Button
                type="button"
                variant="outline"
                disabled={form.processing || form.data.lines.length >= 50}
                onClick={() => form.setData('lines', [...form.data.lines, { service_id: '', quantity: '1' }])}
            >
                Ajouter une ligne
            </Button>
            <p className="text-muted-foreground text-sm">Le total sera calculé à l’enregistrement avec les tarifs actuels du catalogue.</p>
            {invoice && (
                <div>
                    <label htmlFor="reason" className="mb-2 block text-sm">
                        Motif de correction
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
            )}
            {!services.length && <p>Aucun acte actif dans cette devise. Un administrateur doit compléter le catalogue.</p>}
            <div className="flex gap-3">
                <Button disabled={form.processing || !services.length}>Enregistrer le brouillon</Button>
                {onCancel && (
                    <Button type="button" variant="outline" disabled={form.processing} onClick={onCancel}>
                        Annuler les modifications
                    </Button>
                )}
            </div>
        </form>
    );
}
