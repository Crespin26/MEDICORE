<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class MobileSessionController
{
    /** Expose l’identité minimale du compte et ses permissions, sans secret ni contenu clinique. */
    public static function profile(User $user): array
    {
        return [...$user->only('id', 'name', 'email', 'role'), 'permissions' => Role::tryFrom($user->role)?->permissions() ?? []];
    }

    /** Authentifie un compte actif et émet un jeton mobile à durée limitée ; ne crée aucune session navigateur. */
    public function store(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255'], 'password' => ['required', 'string', 'max:1000'], 'device_name' => ['required', 'string', 'max:100']]);

        // La vérification et l’émission partagent le verrou des changements d’accès/mot de passe.
        return DB::transaction(function () use ($data) {
            if (DB::getDriverName() === 'sqlite') {
                User::where('email', $data['email'])->update(['updated_at' => DB::raw('updated_at')]);
            }
            $user = User::where('email', $data['email'])->lockForUpdate()->first();
            $valid = $user ? Hash::check($data['password'], $user->password) : (Hash::make('invalid-login') && false);
            if (! $valid || $user->status !== 'active' || Role::tryFrom($user->role) === null) {
                throw ValidationException::withMessages(['email' => 'Identifiants incorrects ou accès indisponible.']);
            }
            $expires = now()->addHours(8);
            $token = $user->createToken($data['device_name'], ['mobile'], $expires);

            return response()->json(['data' => ['token' => $token->plainTextToken, 'token_type' => 'Bearer', 'expires_at' => $expires->toIso8601String(), 'user' => self::profile($user)]], 201);
        }, 5);
    }

    /** Renvoie le profil du compte authentifié par le jeton mobile. */
    public function show(Request $request)
    {
        return response()->json(['data' => self::profile($request->user())]);
    }

    /** Révoque seulement le jeton de l’appareil courant. */
    public function destroy(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
