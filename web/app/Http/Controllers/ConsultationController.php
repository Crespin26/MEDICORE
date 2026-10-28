<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveConsultationRequest;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\Patient;
use App\Services\ConsultationRecorder;
use App\Support\ClinicTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class ConsultationController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['patient_id' => ['nullable', 'integer', 'exists:patients,id'], 'status' => ['nullable', 'in:draft,validated']]);

        return Inertia::render('consultations/index', [
            'consultations' => Consultation::with('patient:id,first_name,last_name')->where('doctor_id', $request->user()->id)
                ->when($filters['patient_id'] ?? null, fn ($query, $patient) => $query->where('patient_id', $patient))
                ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
                ->orderByDesc('consulted_at')->orderByDesc('id')->paginate(20)->withQueryString()->through(fn ($consultation) => [
                    'id' => $consultation->id, 'patient_id' => $consultation->patient_id, 'patient' => $consultation->patient->last_name.' '.$consultation->patient->first_name,
                    'consulted_at' => ClinicTime::local($consultation->consulted_at), 'status' => $consultation->status, 'reason' => $consultation->reason,
                ]),
            'filters' => ['patient_id' => (string) ($filters['patient_id'] ?? ''), 'status' => $filters['status'] ?? ''], 'timezone' => config('clinic.timezone'),
        ]);
    }

    public function create(Request $request, Patient $patient)
    {
        $data = $request->validate(['appointment_id' => ['nullable', 'integer', 'exists:appointments,id']]);
        $appointment = null;
        if (! empty($data['appointment_id'])) {
            $appointment = Appointment::findOrFail($data['appointment_id']);
            abort_unless($appointment->doctor_id === $request->user()->id && $appointment->patient_id === $patient->id, 403);
            $existing = Consultation::where('appointment_id', $appointment->id)->first();
            if ($existing) {
                return to_route('consultations.show', $existing);
            }
            abort_unless(in_array($appointment->status, ['arrived', 'completed'], true) && ! $appointment->starts_at->isFuture(), 422, 'Le rendez-vous doit être arrivé ou terminé.');
        }

        return Inertia::render('consultations/create', [
            'patient' => $patient->only('id', 'first_name', 'last_name', 'birth_date'),
            'appointmentId' => $appointment?->id, 'timezone' => config('clinic.timezone'),
            'now' => ClinicTime::local(CarbonImmutable::now('UTC')),
        ]);
    }

    public function store(SaveConsultationRequest $request, Patient $patient, ConsultationRecorder $recorder)
    {
        $consultation = $recorder->save($request->user(), $patient, $request->validated());

        return to_route('consultations.show', $consultation)->with('status', 'Consultation enregistrée.');
    }

    public function show(Request $request, Consultation $consultation)
    {
        Gate::authorize('view', $consultation);
        $consultation->load(['patient:id,first_name,last_name,birth_date', 'doctor:id,name']);

        return Inertia::render('consultations/show', [
            'consultation' => [
                ...$consultation->toArray(), 'consulted_at' => ClinicTime::local($consultation->consulted_at),
            ],
            'revisions' => $consultation->revisions()->with('author:id,name')->orderByDesc('version')->paginate(10)->withQueryString(),
            'timezone' => config('clinic.timezone'),
        ]);
    }

    public function update(SaveConsultationRequest $request, Consultation $consultation, ConsultationRecorder $recorder)
    {
        Gate::authorize('update', $consultation);
        $recorder->save($request->user(), $consultation->patient, $request->validated(), $consultation->id);

        return to_route('consultations.show', $consultation)->with('status', 'Consultation et historique mis à jour.');
    }
}
