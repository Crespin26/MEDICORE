<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\DoctorAvailability;
use App\Models\StaffAbsence;
use App\Models\User;
use App\Services\AppointmentScheduler;
use App\Support\ClinicHours;
use App\Support\ClinicTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class DoctorAvailabilityController extends Controller
{
    /** Réserve la mutation à un administrateur ou au médecin propriétaire du créneau. */
    private function authorizeDoctor(Request $request, int $doctorId): void
    {
        abort_unless($request->user()->role === 'admin' || ($request->user()->role === 'doctor' && $request->user()->id === $doctorId), 403);
    }

    /** Pagine les disponibilités du jour en limitant un médecin à ses propres créneaux. */
    public function index(Request $request)
    {
        $filters = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $day = CarbonImmutable::parse($filters['date'] ?? CarbonImmutable::now(config('clinic.timezone'))->toDateString(), config('clinic.timezone'))->startOfDay();
        $doctors = User::where('role', 'doctor')->where('status', 'active')->when($request->user()->role === 'doctor', fn ($query) => $query->whereKey($request->user()->id))->orderBy('name')->get(['id', 'name']);
        $slots = DoctorAvailability::with('doctor:id,name')->where('starts_at', '>=', $day->utc())->where('starts_at', '<', $day->addDay()->utc())
            ->when($request->user()->role === 'doctor', fn ($query) => $query->where('doctor_id', $request->user()->id))->orderBy('starts_at')->orderBy('id')->paginate(20)->withQueryString()
            ->through(fn ($slot) => ['id' => $slot->id, 'doctor' => $slot->doctor->name, 'starts_at' => ClinicTime::local($slot->starts_at), 'ends_at' => ClinicTime::local($slot->ends_at)]);

        return Inertia::render('appointments/availability', ['doctors' => $doctors, 'slots' => $slots, 'date' => $day->toDateString(), 'timezone' => config('clinic.timezone')]);
    }

    /** Crée une disponibilité future sans chevauchement ni absence et dans les horaires de la clinique. */
    public function store(Request $request, AppointmentScheduler $scheduler)
    {
        $data = $request->validate(['doctor_id' => ['required', 'integer', 'exists:users,id']]);
        $this->authorizeDoctor($request, (int) $data['doctor_id']);
        [$start, $end] = ClinicTime::interval($request);
        $scheduler->transaction(function () use ($data, $start, $end, $scheduler) {
            $scheduler->lockDoctors([(int) $data['doctor_id']]);
            $doctor = User::findOrFail($data['doctor_id']);
            if ($doctor->role !== 'doctor' || $doctor->status !== 'active') {
                throw ValidationException::withMessages(['doctor_id' => 'Choisissez un médecin actif.']);
            }
            if ($start < CarbonImmutable::now('UTC')) {
                throw ValidationException::withMessages(['starts_at' => 'La disponibilité doit commencer dans le futur.']);
            }
            if (StaffAbsence::where('user_id', $doctor->id)->where('status', 'active')->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists()) {
                throw ValidationException::withMessages(['starts_at' => 'Cette plage chevauche une absence active du médecin.']);
            }
            ClinicHours::assertOpen($start, $end);
            if (DoctorAvailability::where('doctor_id', $doctor->id)->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists()) {
                throw ValidationException::withMessages(['starts_at' => 'Une disponibilité couvre déjà tout ou partie de ce créneau.']);
            }
            DoctorAvailability::create([...$data, 'starts_at' => $start, 'ends_at' => $end]);
        });

        return back(fallback: route('availabilities.index'))->with('status', 'Disponibilité ajoutée.');
    }

    /** Supprime une disponibilité uniquement si aucun rendez-vous actif ne dépend du créneau. */
    public function destroy(Request $request, DoctorAvailability $availability, AppointmentScheduler $scheduler)
    {
        $this->authorizeDoctor($request, $availability->doctor_id);
        $scheduler->transaction(function () use ($availability, $scheduler) {
            $scheduler->lockDoctors([$availability->doctor_id]);
            $availability = DoctorAvailability::whereKey($availability->id)->lockForUpdate()->firstOrFail();
            if (Appointment::where('doctor_id', $availability->doctor_id)->whereIn('status', Appointment::OPEN)->where('starts_at', '<', $availability->ends_at)->where('ends_at', '>', $availability->starts_at)->exists()) {
                throw ValidationException::withMessages(['availability' => 'Déplacez ou annulez les rendez-vous de ce créneau avant de retirer cette disponibilité.']);
            }
            $availability->delete();
        });

        return back(fallback: route('availabilities.index'))->with('status', 'Disponibilité retirée.');
    }
}
