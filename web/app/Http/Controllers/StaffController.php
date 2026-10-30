<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\User;
use App\Notifications\StaffInvitation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Throwable;

class StaffController extends Controller
{
    /** Pagine les comptes et expose les rôles disponibles à l’administrateur. */
    public function index()
    {
        return Inertia::render('staff/index', [
            'staff' => User::select('id', 'name', 'email', 'role', 'status')->orderBy('name')->paginate(20),
            'roles' => collect(Role::cases())->map(fn ($role) => ['value' => $role->value, 'label' => $role->label()]),
        ]);
    }

    /** Crée un compte en attente avec un mot de passe aléatoire puis envoie son invitation. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'lowercase', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::enum(Role::class)],
        ]);
        $user = new User(['name' => $data['name'], 'email' => $data['email'], 'password' => Str::random(64)]);
        $user->role = $data['role'];
        $user->status = 'pending';
        $user->save();

        return $this->sendInvitation($user);
    }

    /** Renvoie une invitation uniquement pour un compte encore en attente. */
    public function invite(User $user)
    {
        abort_unless($user->status === 'pending', 409);

        return $this->sendInvitation($user);
    }

    /** Renouvelle le jeton haché avant envoi ; un échec du transport laisse le compte réinvitable. */
    private function sendInvitation(User $user)
    {
        $token = Str::random(64);
        // Persist before sending so the account remains resendable on transport failure.
        $user->forceFill(['invitation_token' => hash('sha256', $token), 'invitation_expires_at' => now()->addHours(48)])->save();
        try {
            $user->notify(new StaffInvitation(route('invitation.show', ['token' => $token])));
        } catch (Throwable $error) {
            report($error);

            return back()->with('status', 'Compte en attente enregistré, mais l’envoi a échoué. Vérifiez le transport e-mail puis renvoyez l’invitation.');
        }

        return back()->with('status', config('mail.default') === 'log'
            ? 'Invitation créée dans le journal local (mode développement, aucun e-mail externe envoyé).'
            : 'Invitation transmise au transport e-mail.');
    }

    /** Change les accès sous verrou, protège le dernier administrateur et révoque les sessions suspendues. */
    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'role' => ['required', Rule::enum(Role::class)],
            'status' => ['required', Rule::in(['active', 'suspended'])],
        ]);
        abort_if($user->is($request->user()), 403, 'Vous ne pouvez pas modifier vos propres accès.');
        DB::transaction(function () use ($request, $user, $data) {
            // Serialize administrative changes and recheck the actor after acquiring locks.
            $admins = User::where('role', 'admin')->where('status', 'active')->orderBy('id')->lockForUpdate()->get();
            abort_unless(User::findOrFail($request->user()->id)->hasPermission('users.manage'), 403);
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_if($user->status === 'pending', 409, 'Le compte doit accepter son invitation.');
            if ($user->role === 'admin' && $user->status === 'active' && ($data['role'] !== 'admin' || $data['status'] !== 'active')) {
                abort_if($admins->count() <= 1, 409, 'Conservez au moins un administrateur actif.');
            }
            $user->forceFill($data);
            if ($data['status'] === 'suspended') {
                $user->remember_token = Str::random(60);
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
            $user->save();
        });

        return back()->with('status', 'Accès du compte mis à jour.');
    }
}
