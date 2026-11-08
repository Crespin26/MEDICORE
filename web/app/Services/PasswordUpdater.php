<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PasswordUpdater
{
    /** Revérifie le mot de passe courant sous verrou avant de changer les identifiants et révoquer les accès. */
    public function change(User $actor, string $current, string $password): void
    {
        DB::transaction(function () use ($actor, $current, $password) {
            $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->status === 'active', 403);
            if (! Hash::check($current, $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'Le mot de passe actuel est incorrect.']);
            }
            $user->update(['password' => Hash::make($password)]);
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
        });
    }

    /** Applique la réinitialisation uniquement à un compte toujours actif au moment de l’écriture. */
    public function reset(User $account, string $password): User
    {
        return DB::transaction(function () use ($account, $password) {
            $user = User::whereKey($account->id)->lockForUpdate()->firstOrFail();
            if ($user->status !== 'active' || $user->email !== $account->email || ! hash_equals($user->password, $account->password)) {
                throw ValidationException::withMessages(['email' => 'Ce lien de réinitialisation ne peut pas être utilisé.']);
            }
            $user->update(['password' => Hash::make($password)]);

            return $user;
        });
    }
}
