<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class InvitationController extends Controller
{
    private function account(string $token)
    {
        return User::where('invitation_token', hash('sha256', $token))
            ->where('status', 'pending')->where('invitation_expires_at', '>', now());
    }

    public function show(string $token)
    {
        abort_unless($this->account($token)->exists(), 410, 'Invitation expirée ou déjà utilisée. Demandez un nouveau lien.');

        return Inertia::render('auth/accept-invitation', ['token' => $token]);
    }

    public function store(Request $request, string $token)
    {
        $data = $request->validate(['password' => ['required', 'confirmed', Password::min(12), 'max:72']]);
        DB::transaction(function () use ($token, $data) {
            $user = $this->account($token)->lockForUpdate()->first();
            abort_unless($user, 410, 'Invitation expirée ou déjà utilisée.');
            $user->forceFill([
                'password' => $data['password'], 'status' => 'active', 'email_verified_at' => now(),
                'invitation_token' => null, 'invitation_expires_at' => null, 'remember_token' => Str::random(60),
            ])->save();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
        });

        return to_route('login')->with('status', 'Compte activé. Vous pouvez vous connecter.');
    }
}
