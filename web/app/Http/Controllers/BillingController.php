<?php

namespace App\Http\Controllers;

use App\Models\BillableService;
use App\Models\BillingEvent;
use App\Models\ClinicSetting;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Patient;
use App\Services\BillingService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class BillingController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in(array_keys(Invoice::STATUSES))], 'patient_id' => ['nullable', 'integer', 'exists:patients,id']]);

        return Inertia::render('billing/index', ['invoices' => Invoice::when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->when($filters['patient_id'] ?? null, fn ($q, $v) => $q->where('patient_id', $v))->orderByDesc('id')->paginate(20)->withQueryString(), 'filters' => ['status' => $filters['status'] ?? '', 'patient_id' => (string) ($filters['patient_id'] ?? '')], 'statuses' => Invoice::STATUSES]);
    }

    public function patients(Request $request)
    {
        $data = $request->validate(['search' => ['required', 'string', 'min:2', 'max:100']]);
        $query = Patient::query();
        if (preg_match('/^MC-([0-9]+)$/i', $data['search'], $matches)) {
            $query->whereKey($matches[1]);
        } else {
            foreach (explode(' ', Patient::searchText($data['search'])) as $word) {
                $query->whereRaw("search_text LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word).'%']);
            }
        }

        return response()->json($query->orderBy('last_name')->limit(20)->get(['id', 'first_name', 'last_name', 'birth_date']));
    }

    public function create()
    {
        return Inertia::render('billing/create', ['services' => BillableService::where('active', true)->where('currency', config('clinic.currency'))->orderBy('name')->get(), 'currency' => config('clinic.currency')]);
    }

    private function lines(Request $request): array
    {
        return $request->validate(['lines' => ['required', 'array', 'min:1', 'max:50'], 'lines.*' => ['array:service_id,quantity'], 'lines.*.service_id' => ['required', 'integer', 'distinct', 'exists:billable_services,id'], 'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000']]);
    }

    public function store(Request $request, BillingService $service)
    {
        $data = $request->validate(['patient_id' => ['required', 'integer', 'exists:patients,id'], 'token' => ['required', 'uuid'], 'currency' => ['nullable', Rule::in(array_keys(Money::SCALES))]]);
        $invoice = $service->create($request->user(), [...$data, ...$this->lines($request)]);

        return to_route('billing.show', $invoice)->with('status', 'Brouillon enregistré.');
    }

    public function show(Invoice $invoice)
    {
        $invoice->load(['lines', 'payments']);

        return Inertia::render('billing/show', ['invoice' => $invoice, 'events' => $invoice->events()->orderByDesc('id')->paginate(10)->withQueryString(), 'services' => BillableService::where('active', true)->where('currency', $invoice->currency)->orderBy('name')->get(), 'statuses' => Invoice::STATUSES, 'timezone' => config('clinic.timezone')]);
    }

    public function update(Request $request, Invoice $invoice, BillingService $service)
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:1000']]);
        $service->update($request->user(), $invoice->id, [...$data, ...$this->lines($request)]);

        return to_route('billing.show', $invoice)->with('status', 'Brouillon corrigé avec les tarifs actuels.');
    }

    public function issue(Request $request, Invoice $invoice, BillingService $service)
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $service->issue($request->user(), $invoice->id, $data['version']);

        return to_route('billing.show', $invoice)->with('status', 'Facture émise.');
    }

    public function cancel(Request $request, Invoice $invoice, BillingService $service)
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:1000']]);
        $service->cancel($request->user(), $invoice->id, $data['version'], $data['reason']);

        return to_route('billing.show', $invoice)->with('status', 'Facture annulée et conservée.');
    }

    public function pay(Request $request, Invoice $invoice, BillingService $service)
    {
        $data = $request->validate(['amount' => ['required', 'string', 'max:32'], 'token' => ['required', 'uuid'], 'currency' => ['nullable', Rule::in(array_keys(Money::SCALES))], 'method' => ['required', 'in:cash,transfer,card'], 'reference' => ['nullable', 'string', 'max:100']]);
        $payment = $service->pay($request->user(), $invoice->id, $data);

        return to_route('billing.show', $invoice)->with('status', $payment->voided_at ? 'Cette demande correspond à un paiement déjà annulé. Aucune nouvelle écriture créée.' : 'Paiement enregistré — reçu '.$payment->receipt_number.'.');
    }

    public function void(Request $request, InvoicePayment $payment, BillingService $service)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $service->void($request->user(), $payment->id, $data['reason']);

        return to_route('billing.show', $payment->invoice_id)->with('status', 'Écriture de paiement annulée. Aucun remboursement automatique effectué.');
    }

    public function print(Invoice $invoice)
    {
        $invoice->load(['lines', 'payments']);

        return view('billing.invoice', ['invoice' => $invoice]);
    }

    public function receipt(InvoicePayment $payment)
    {
        $payment->load('invoice');

        return view('billing.receipt', ['payment' => $payment]);
    }

    public function catalog()
    {
        return Inertia::render('billing/catalog', ['services' => BillableService::orderBy('code')->paginate(20), 'currency' => config('clinic.currency'), 'timezone' => config('clinic.timezone'), 'events' => BillingEvent::whereNotNull('billable_service_id')->orderByDesc('id')->paginate(10, ['*'], 'events_page')->withQueryString()]);
    }

    public function saveService(Request $request, BillingService $billing, ?BillableService $service = null)
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('billable_services', 'code')->ignore($service?->id)], 'name' => ['required', 'string', 'max:255'], 'price' => ['required', 'string', 'max:32'], 'currency' => ['nullable', Rule::in(array_keys(Money::SCALES))], 'active' => ['required', 'boolean'], 'version' => [$service ? 'required' : 'nullable', 'integer', 'min:1'], 'reason' => [$service ? 'required' : 'nullable', 'string', 'max:1000']]);
        $billing->transaction($request->user(), 'catalog.manage', function ($actor) use ($billing, $data, $service) {
            $item = $service ? BillableService::whereKey($service->id)->lockForUpdate()->firstOrFail() : new BillableService;
            if ($service && $item->version !== (int) $data['version']) {
                throw ValidationException::withMessages(['version' => 'Ce tarif a changé. Rechargez la page.']);
            }
            if (BillableService::where('code', $data['code'])->when($service, fn ($query) => $query->whereKeyNot($service->id))->exists()) {
                throw ValidationException::withMessages(['code' => 'Ce code existe déjà.']);
            }
            $setting = ClinicSetting::whereKey(1)->lockForUpdate()->firstOrFail();
            $currency = $setting->currency ?? config('clinic.currency');
            if (! $service && isset($data['currency']) && $data['currency'] !== $currency) {
                throw ValidationException::withMessages(['price' => 'La devise de l’établissement a changé. Rechargez le formulaire.']);
            }
            $item->currency ??= $currency;
            $item->code = $data['code'];
            $item->name = $data['name'];
            $item->price_minor = Money::minor($data['price'], $item->currency, 'price');
            $item->active = $data['active'];
            $item->version = $service ? $item->version + 1 : 1;
            $item->save();
            $billing->event($actor, $service ? 'catalog.updated' : 'catalog.created', service: $item, reason: $data['reason'] ?? null);
        });

        return to_route('billing.catalog')->with('status', 'Catalogue enregistré.');
    }
}
