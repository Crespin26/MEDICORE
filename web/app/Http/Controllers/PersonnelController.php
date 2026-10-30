<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\PersonnelEvent;
use App\Models\ProfessionalProfile;
use App\Models\StaffAbsence;
use App\Models\User;
use App\Services\PersonnelManager;
use App\Support\ClinicTime;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class PersonnelController extends Controller
{
    /** Recherche et pagine les comptes avec leur fiche professionnelle selon les filtres autorisés. */
    public function index(Request $request)
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'role' => ['nullable', Rule::enum(Role::class)], 'status' => ['nullable', 'in:active,pending,suspended']]);
        $query = User::with('professionalProfile')->when($data['role'] ?? null, fn ($q, $v) => $q->where('role', $v))->when($data['status'] ?? null, fn ($q, $v) => $q->where('status', $v));
        if (! empty($data['search'])) {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $data['search']).'%';
            $query->where(fn ($q) => $q->whereRaw("name LIKE ? ESCAPE '!'", [$term])->orWhereRaw("email LIKE ? ESCAPE '!'", [$term])->orWhereHas('professionalProfile', fn ($q) => $q->whereRaw("employee_number LIKE ? ESCAPE '!'", [$term])->orWhereRaw("job_title LIKE ? ESCAPE '!'", [$term])->orWhereRaw("department LIKE ? ESCAPE '!'", [$term])));
        }

        return Inertia::render('personnel/index', ['staff' => $query->orderBy('name')->orderBy('id')->paginate(20)->withQueryString()->through(fn ($user) => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role, 'status' => $user->status, 'profile' => $user->professionalProfile]),
            'filters' => ['search' => $data['search'] ?? '', 'role' => $data['role'] ?? '', 'status' => $data['status'] ?? ''], 'roles' => collect(Role::cases())->mapWithKeys(fn ($role) => [$role->value => $role->label()])]);
    }

    /** Affiche la fiche du membre connecté sans commandes de gestion. */
    public function own(Request $request)
    {
        return $this->render($request, $request->user(), false);
    }

    /** Affiche une fiche professionnelle, les absences et l’historique selon les droits de l’acteur. */
    public function show(Request $request, User $user)
    {
        return $this->render($request, $user, true);
    }

    /** Assemble fiche et absences paginées ; ajoute l’historique uniquement pour la gestion. */
    private function render(Request $request, User $user, bool $manage)
    {
        $absences = StaffAbsence::where('user_id', $user->id)->orderByDesc('starts_at')->orderByDesc('id')->paginate(10, ['*'], 'absences_page')->withQueryString()->through(fn ($absence) => [...$absence->toArray(), 'starts_at' => ClinicTime::local($absence->starts_at), 'ends_at' => ClinicTime::local($absence->ends_at)]);
        $props = ['member' => $user->only('id', 'name', 'email', 'role', 'status'), 'profile' => ProfessionalProfile::where('user_id', $user->id)->first(), 'absences' => $absences, 'canManage' => $manage, 'categories' => StaffAbsence::CATEGORIES, 'timezone' => config('clinic.timezone')];
        if ($manage) {
            $props['events'] = PersonnelEvent::where('user_id', $user->id)->orderByDesc('id')->paginate(10, ['*'], 'events_page')->withQueryString();
        }

        return Inertia::render('personnel/show', $props);
    }

    /** Valide la fiche puis délègue sa mise à jour versionnée au gestionnaire de personnel. */
    public function update(Request $request, User $user, PersonnelManager $manager)
    {
        $data = $request->validate([
            'employee_number' => ['nullable', 'string', 'max:40', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('professional_profiles', 'employee_number')->ignore($user->id, 'user_id')],
            'job_title' => ['required', 'string', 'max:150'], 'department' => ['nullable', 'string', 'max:150'],
            'specialties' => ['nullable', 'array', 'max:10'], 'specialties.*' => ['required', 'string', 'max:100', 'distinct'],
            'professional_phone' => ['nullable', 'string', 'max:30'], 'license_number' => ['nullable', 'string', 'max:100'],
            'started_on' => ['nullable', 'date_format:Y-m-d'], 'version' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:1000'],
        ]);
        $data['specialties'] ??= [];
        $manager->profile($request->user(), $user->id, $data);

        return to_route('personnel.show', $user)->with('status', 'Fiche professionnelle enregistrée.');
    }

    /** Valide et délègue la déclaration d’une absence au gestionnaire de personnel. */
    public function absence(Request $request, User $user, PersonnelManager $manager)
    {
        $data = $request->validate(['starts_at' => ['required', 'date_format:Y-m-d\TH:i'], 'ends_at' => ['required', 'date_format:Y-m-d\TH:i'], 'category' => ['required', Rule::in(array_keys(StaffAbsence::CATEGORIES))], 'reason' => ['required', 'string', 'max:500']]);
        $manager->absence($request->user(), $user->id, $data);

        return to_route('personnel.show', $user)->with('status', 'Absence enregistrée.');
    }

    /** Valide le motif puis délègue l’annulation versionnée d’une absence. */
    public function cancel(Request $request, User $user, StaffAbsence $absence, PersonnelManager $manager)
    {
        abort_unless($absence->user_id === $user->id, 404);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:1000']]);
        $manager->cancel($request->user(), $user->id, $absence->id, $data['version'], $data['reason']);

        return to_route('personnel.show', $user)->with('status', 'Absence annulée et conservée dans l’historique.');
    }
}
