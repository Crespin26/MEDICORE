<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserProfileUpdater
{
    /** Applique le même contrat web/mobile ; rôle, statut et mot de passe ne sont jamais modifiés ici. */
    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->status === 'active', 403);
            $user->fill(array_intersect_key($data, array_flip(['name', 'email'])));
            if ($user->isDirty('email')) {
                $user->email_verified_at = null;
            }
            $user->save();

            return $user;
        });
    }
}
