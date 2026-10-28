<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\DoctorAvailability;
use App\Models\Patient;
use App\Models\StaffAbsence;
use App\Models\User;
use App\Services\AppointmentScheduler;
use App\Support\ClinicTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AppointmentController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'doctor_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', Rule::in(array_keys(Appointment::STATUSES))],
        ]);
        $day = CarbonImmutable::parse($filters['date'] ?? CarbonImmutable::now(config('clinic.timezone'))->toDateString(), config('clinic.timezone'))->startOfDay();
        $query = Appointment::with(['patient:id,first_name,last_name,birth_date', 'doctor:id,name']);
        if ($request->user()->role === 'doctor') {
            $query->where('doctor_id', $request->user()->id);
        } elseif (! empty($filters['doctor_id'])) {
            $query->where('doctor_id', $filters['doctor_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        $calendar = [];
        for ($i = 0; $i < 7; $i++) {
            $date = $day->startOfWeek()->addDays($i);
            $daily = (clone $query)->where('starts_at', '>=', $date->utc())->where('starts_at', '<', $date->addDay()->utc());
            $calendar[] = ['date' => $date->toDateString(), 'count' => (clone $daily)->count(), 'items' => $daily->orderBy('starts_at')->orderBy('id')->limit(3)->get()->map(fn ($appointment) => $this->record($appointment))];
        }
        $daily = (clone $query)->where('starts_at', '>=', $day->utc())->where('starts_at', '<', $day->addDay()->utc())->orderBy('starts_at')->orderBy('id')->paginate(20)->withQueryString()->through(fn ($appointment) => $this->record($appointment));
        $doctors = User::where('role', 'doctor')->where('status', 'active')->when($request->user()->role === 'doctor', fn ($query) => $query->whereKey($request->user()->id))->orderBy('name')->get(['id', 'name']);
        $availability = DoctorAvailability::with('doctor:id,name')->where('starts_at', '>=', $day->utc())->where('starts_at', '<', $day->addDay()->utc())
            ->when($request->user()->role === 'doctor', fn ($query) => $query->where('doctor_id', $request->user()->id))
            ->when($request->user()->role !== 'doctor' && ! empty($filters['doctor_id']), fn ($query) => $query->where('doctor_id', $filters['doctor_id']))
            ->orderBy('starts_at')->get()->map(fn ($slot) => ['id' => $slot->id, 'doctor' => $slot->doctor->name, 'starts_at' => ClinicTime::local($slot->starts_at), 'ends_at' => ClinicTime::local($slot->ends_at)]);

        $absences = StaffAbsence::with('user:id,name')->where('status', 'active')->where('starts_at', '<', $day->addDay()->utc())->where('ends_at', '>', $day->utc())
            ->whereHas('user', fn ($query) => $query->where('role', 'doctor'))
            ->when($request->user()->role === 'doctor', fn ($query) => $query->where('user_id', $request->user()->id))
            ->when($request->user()->role !== 'doctor' && ! empty($filters['doctor_id']), fn ($query) => $query->where('user_id', $filters['doctor_id']))
            ->orderBy('starts_at')->get()->map(fn ($absence) => ['id' => $absence->id, 'doctor' => $absence->user->name, 'starts_at' => ClinicTime::local($absence->starts_at), 'ends_at' => ClinicTime::local($absence->ends_at)]);

        return Inertia::render('appointments/index', [
            'absences' => $absences, 'appointments' => $daily, 'calendar' => $calendar, 'doctors' => $doctors, 'availability' => $availability,
            'filters' => ['date' => $day->toDateString(), 'doctor_id' => $request->user()->role === 'doctor' ? (string) $request->user()->id : (string) ($filters['doctor_id'] ?? ''), 'status' => $filters['status'] ?? ''],
            'timezone' => config('clinic.timezone'), 'statuses' => Appointment::STATUSES,
        ]);
    }

    private function record(Appointment $appointment): array
    {
        return ['id' => $appointment->id, 'patient_id' => $appointment->patient_id, 'doctor_id' => $appointment->doctor_id,
            'patient' => $appointment->patient->last_name.' '.$appointment->patient->first_name, 'doctor' => $appointment->doctor->name,
            'starts_at' => ClinicTime::local($appointment->starts_at), 'ends_at' => ClinicTime::local($appointment->ends_at), 'status' => $appointment->status,
            'transitions' => Appointment::TRANSITIONS[$appointment->status],
        ];
    }

    public function patients(Request $request)
    {
        $data = $request->validate(['search' => ['required', 'string', 'min:2', 'max:100']]);
        $query = Patient::query();
        if (preg_match('/^MC-([0-9]+)$/i', $data['search'], $match)) {
            $query->whereKey($match[1]);
        } else {
            foreach (explode(' ', Patient::searchText($data['search'])) as $word) {
                $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word).'%';
                $query->whereRaw("search_text LIKE ? ESCAPE '!'", [$term]);
            }
        }

        return response()->json($query->orderBy('last_name')->orderBy('first_name')->limit(20)->get(['id', 'first_name', 'last_name', 'birth_date']));
    }

    public function store(Request $request, AppointmentScheduler $scheduler)
    {
        $data = $request->validate(['patient_id' => ['required', 'integer', 'exists:patients,id'], 'doctor_id' => ['required', 'integer', 'exists:users,id']]);
        [$start, $end] = ClinicTime::interval($request);
        $scheduler->book($data, $start, $end);

        return back(fallback: route('appointments.index'))->with('status', 'Rendez-vous planifié.');
    }

    public function update(Request $request, Appointment $appointment, AppointmentScheduler $scheduler)
    {
        $data = $request->validate(['patient_id' => ['required', 'integer', 'exists:patients,id'], 'doctor_id' => ['required', 'integer', 'exists:users,id']]);
        [$start, $end] = ClinicTime::interval($request);
        $scheduler->book($data, $start, $end, $appointment->id);

        return back(fallback: route('appointments.index'))->with('status', 'Rendez-vous modifié ; sa confirmation est à renouveler.');
    }

    public function status(Request $request, Appointment $appointment, AppointmentScheduler $scheduler)
    {
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(Appointment::STATUSES))]]);
        $scheduler->transaction(function () use ($request, $appointment, $scheduler, $data) {
            $scheduler->lockDoctors(User::where('role', 'doctor')->pluck('id')->push($appointment->doctor_id)->all());
            $appointment = Appointment::whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            if ($request->user()->role === 'doctor') {
                abort_unless($appointment->doctor_id === $request->user()->id && in_array($data['status'], ['arrived', 'completed', 'absent'], true), 403);
            }
            if (! in_array($data['status'], Appointment::TRANSITIONS[$appointment->status], true)) {
                throw ValidationException::withMessages(['status' => 'Cette transition n’est pas permise depuis le statut actuel. Actualisez l’agenda.']);
            }
            if (in_array($data['status'], ['arrived', 'completed', 'absent']) && $appointment->starts_at->isFuture()) {
                throw ValidationException::withMessages(['status' => 'Ce statut ne peut pas être appliqué avant le début du rendez-vous.']);
            }
            $appointment->status = $data['status'];
            $appointment->save();
        });

        return back(fallback: route('appointments.index'))->with('status', 'Statut du rendez-vous mis à jour.');
    }
}
