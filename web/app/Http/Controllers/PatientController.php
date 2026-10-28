<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\Patient;
use App\Services\MedicalAudit;
use App\Support\ClinicTime;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PatientController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'birth_from' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'birth_to' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', ...($request->filled('birth_from') ? ['after_or_equal:birth_from'] : [])],
            'contact' => ['nullable', 'in:with_phone,without_phone'],
        ]);
        $query = Patient::query();
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

        return Inertia::render('patients/index', [
            'patients' => $query->orderBy('last_name')->orderBy('first_name')->orderBy('id')->paginate(20)->withQueryString(),
            'filters' => array_merge(['search' => '', 'birth_from' => '', 'birth_to' => '', 'contact' => ''], $filters),
        ]);
    }

    public function show(Request $request, Patient $patient)
    {
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
            $props['consultations'] = Consultation::where('patient_id', $patient->id)->where('doctor_id', $request->user()->id)
                ->orderByDesc('consulted_at')->orderByDesc('id')->limit(10)->get()->map(fn ($consultation) => [
                    'id' => $consultation->id, 'consulted_at' => ClinicTime::local($consultation->consulted_at), 'status' => $consultation->status,
                ]);
        }

        return Inertia::render('patients/show', $props);
    }

    private function validated(Request $request, ?Patient $patient = null): array
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'birth_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
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
        if ($duplicate) {
            throw ValidationException::withMessages(['first_name' => 'Une fiche porte déjà ces prénom, nom et date de naissance (MC-'.$duplicate->id.'). Vérifiez-la avant de poursuivre.']);
        }

        return $data;
    }

    private function save(Patient $patient, array $data): void
    {
        try {
            $patient->fill($data)->save();
        } catch (UniqueConstraintViolationException $error) {
            throw ValidationException::withMessages(['first_name' => 'Une fiche porte déjà ces prénom, nom et date de naissance. Actualisez la liste et vérifiez-la.']);
        }
    }

    public function store(Request $request)
    {
        $this->save(new Patient, $this->validated($request));

        return to_route('patients.index')->with('status', 'Patient créé.');
    }

    public function update(Request $request, Patient $patient)
    {
        $this->save($patient, $this->validated($request, $patient));

        return back(fallback: route('patients.index'))->with('status', 'Fiche patient mise à jour.');
    }
}
