<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\PatientEvent;
use App\Services\ClinicalAccess;
use App\Services\MedicalAudit;
use App\Support\ClinicTime;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PatientController extends Controller
{
    /** Recherche et pagine les identités, par défaut actives ; partage les filtres entre web et API. */
    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'birth_from' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'birth_to' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', ...($request->filled('birth_from') ? ['after_or_equal:birth_from'] : [])],
            'contact' => ['nullable', 'in:with_phone,without_phone'],
            'state' => ['nullable', 'in:active,archived,all'],
        ]);
        $query = Patient::query();
        if (($filters['state'] ?? 'active') !== 'all') {
            ($filters['state'] ?? 'active') === 'archived' ? $query->whereNotNull('archived_at') : $query->whereNull('archived_at');
        }
        $search = Str::squish($filters['search'] ?? '');
        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                if (preg_match('/^(?:MC-)?([0-9]+)$/i', $search, $matches)) {
                    $query->orWhere('id', $matches[1]);
                }
                $query->orWhere(function ($query) use ($search) {
                    foreach (explode(' ', Patient::searchText($search)) as $word) {
                        // Literal search: user-provided % and _ are not wildcards.
                        $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], Str::lower($word)).'%';
                        $query->whereRaw("search_text LIKE ? ESCAPE '!'", [$term]);
                    }
                });
            });
        }
        $query->when($filters['birth_from'] ?? null, fn ($query, $date) => $query->where('birth_date', '>=', $date));
        $query->when($filters['birth_to'] ?? null, fn ($query, $date) => $query->where('birth_date', '<=', $date));
        if (($filters['contact'] ?? '') === 'with_phone') {
            $query->whereNotNull('phone')->where('phone', '!=', '');
        } elseif (($filters['contact'] ?? '') === 'without_phone') {
            $query->where(fn ($query) => $query->whereNull('phone')->orWhere('phone', ''));
        }

        if ($request->is('api/*')) {
            return response()->json($query->orderBy('last_name')->orderBy('first_name')->orderBy('id')->paginate(20)->withQueryString());
        }

        return Inertia::render('patients/index', [
            'patients' => $query->orderBy('last_name')->orderBy('first_name')->orderBy('id')->paginate(20)->withQueryString(),
            'filters' => array_merge(['search' => '', 'birth_from' => '', 'birth_to' => '', 'contact' => '', 'state' => 'active'], $filters),
        ]);
    }

    /** Expose l’identité ; sur le web, ajoute uniquement les résumés autorisés pour le rôle courant. */
    public function show(Request $request, Patient $patient)
    {
        if ($request->is('api/*')) {
            return response()->json(['data' => $patient]);
        }
        $appointments = Appointment::with('doctor:id,name')->where('patient_id', $patient->id)
            ->when($request->user()->role === 'doctor', fn ($query) => $query->where('doctor_id', $request->user()->id))
            ->orderByDesc('starts_at')->limit(10)->get()->map(fn ($appointment) => [
                'id' => $appointment->id, 'doctor' => $appointment->doctor->name,
                'starts_at' => ClinicTime::local($appointment->starts_at),
                'status' => Appointment::STATUSES[$appointment->status],
            ]);

        $props = ['patient' => $patient, 'appointments' => $appointments];
        if ($request->user()->can('consultations.view')) {
            MedicalAudit::record($request->user(), $patient->id, 'consultations.summary', 'patient', $patient->id);
            $props['consultations'] = Consultation::where('patient_id', $patient->id)->whereIn('doctor_id', ClinicalAccess::authors($request->user(), $patient->id))
                ->orderByDesc('consulted_at')->orderByDesc('id')->limit(10)->get()->map(fn ($consultation) => [
                    'id' => $consultation->id, 'consulted_at' => ClinicTime::local($consultation->consulted_at), 'status' => $consultation->status,
                ]);
        }

        if ($request->user()->can('billing.view')) {
            $props['invoices'] = Invoice::where('patient_id', $patient->id)->orderByDesc('id')->limit(10)->get(['id', 'number', 'currency', 'status', 'total_minor', 'paid_minor']);
        }

        if ($request->user()->role === 'admin') {
            $props['lifecycleEvents'] = PatientEvent::where('patient_id', $patient->id)->whereIn('action', ['identity.distinct-confirmed', 'patient.archived', 'patient.reactivated'])->orderByDesc('id')->limit(30)->get();
        }

        return Inertia::render('patients/show', $props);
    }

    /** Normalise l’identité et contrôle les doublons ; seule une confirmation administrateur motivée les distingue. */
    private function validated(Request $request, ?Patient $patient = null): array
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'birth_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'confirm_distinct' => ['sometimes', 'boolean'],
            'distinct_reason' => ['nullable', 'string', 'min:5', 'max:1000'],
        ]);
        $data['first_name'] = Str::squish($data['first_name']);
        $data['last_name'] = Str::squish($data['last_name']);
        $key = Patient::identityKey($data['first_name'], $data['last_name'], $data['birth_date']);
        $duplicate = Patient::where('identity_key', $key)->when($patient, fn ($query) => $query->whereKeyNot($patient->id))->first();
        if (! $duplicate) {
            // Legacy duplicate groups may contain records without an assigned key.
            $duplicate = Patient::whereNull('identity_key')->when($patient, fn ($query) => $query->whereKeyNot($patient->id))->get()
                ->first(fn ($existing) => Patient::identityKey($existing->first_name, $existing->last_name, $existing->birth_date->format('Y-m-d')) === $key);
        }
        $confirmed = (bool) ($data['confirm_distinct'] ?? false);
        if ($confirmed) {
            abort_unless($request->user()->role === 'admin', 403);
            if (trim($data['distinct_reason'] ?? '') === '') {
                throw ValidationException::withMessages(['distinct_reason' => 'Justifiez la confirmation de deux personnes distinctes.']);
            }
        }
        $unchanged = $patient && Patient::identityKey($patient->first_name, $patient->last_name, $patient->birth_date->format('Y-m-d')) === $key;
        $establishedIdentity = $unchanged && ($patient->distinct_identity || $patient->identity_key === $key);
        $previouslyConfirmed = $unchanged && $patient->distinct_identity;
        if ($duplicate && ! $confirmed && ! $establishedIdentity) {
            throw ValidationException::withMessages(['first_name' => 'Une fiche porte déjà ces prénom, nom et date de naissance (MC-'.$duplicate->id.'). Vérifiez-la avant de poursuivre.']);
        }

        $data['distinct_identity'] = $duplicate && ($confirmed || $previouslyConfirmed);

        return $data;
    }

    /** Enregistre l’identité et son éventuelle justification atomiquement ; traduit les courses d’unicité en erreur de champ. */
    private function save(Patient $patient, array $data, Request $request): void
    {
        try {
            DB::transaction(function () use ($patient, $data, $request) {
                $patient->distinct_identity = $data['distinct_identity'];
                $patient->fill($data)->save();
                if ($data['distinct_identity'] && ($data['confirm_distinct'] ?? false)) {
                    PatientEvent::create(['patient_id' => $patient->id, 'actor_id' => $request->user()->id, 'action' => 'identity.distinct-confirmed', 'reason' => trim($data['distinct_reason']), 'created_at' => now()]);
                }
            });
        } catch (UniqueConstraintViolationException $error) {
            throw ValidationException::withMessages(['first_name' => 'Une fiche porte déjà ces prénom, nom et date de naissance. Actualisez la liste et vérifiez-la.']);
        }
    }

    /** Crée une fiche et renvoie une réponse JSON 201 ou une redirection web. */
    public function store(Request $request)
    {
        $patient = new Patient;
        $this->save($patient, $this->validated($request), $request);
        if ($request->is('api/*')) {
            return response()->json(['data' => $patient->fresh()], 201);
        }

        return to_route('patients.index')->with('status', 'Patient créé.');
    }

    /** Verrouille la fiche avant édition et refuse la modification d’un patient archivé. */
    public function update(Request $request, Patient $patient)
    {
        DB::transaction(function () use ($request, $patient) {
            $patient = Patient::whereKey($patient->id)->lockForUpdate()->firstOrFail();
            $patient->assertActive();
            $this->save($patient, $this->validated($request, $patient), $request);
        });

        if ($request->is('api/*')) {
            return response()->json(['data' => $patient->fresh()]);
        }

        return back(fallback: route('patients.index'))->with('status', 'Fiche patient mise à jour.');
    }
}
