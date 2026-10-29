<?php

namespace App\Console\Commands;

use App\Services\ClinicalBackup;
use Illuminate\Console\Command;
use Throwable;

class RestoreClinicalBackup extends Command
{
    protected $signature = 'medicore:restore {archive : Fichier .mcb} {destination : Nouveau dossier privé}';

    protected $description = 'Restaurer et contrôler une sauvegarde dans un dossier neuf sans remplacer les données actives';

    public function handle(ClinicalBackup $backup): int
    {
        try {
            $backup->restore($this->argument('archive'), $this->argument('destination'));
            $this->info('Restauration isolée vérifiée. La base et les documents actifs sont inchangés. Effectuer la recette avant toute bascule.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Restauration échouée. Vérifier archive, clés historiques, extensions et nouveau dossier privé.');

            return self::FAILURE;
        }
    }
}
