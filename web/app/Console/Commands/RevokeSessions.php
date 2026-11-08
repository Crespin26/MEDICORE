<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RevokeSessions extends Command
{
    protected $signature = 'medicore:revoke-sessions';

    protected $description = 'Révoquer les accès web, mobiles et liens de réinitialisation pendant une maintenance de sécurité';

    /** Ferme les accès existants sans toucher aux comptes, rôles, mots de passe ou données métier. */
    public function handle(): int
    {
        if (! app()->isDownForMaintenance()) {
            $this->error('Activer la maintenance et arrêter les écritures avant de révoquer les accès.');

            return self::FAILURE;
        }
        DB::transaction(function () {
            DB::table('sessions')->delete();
            DB::table('personal_access_tokens')->delete();
            DB::table('password_reset_tokens')->delete();
            DB::table('users')->update(['remember_token' => null]);
        });
        $this->info('Sessions, jetons mobiles, liens de réinitialisation et accès mémorisés révoqués.');

        return self::SUCCESS;
    }
}
