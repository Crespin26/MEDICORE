<?php

namespace App\Console\Commands;

use App\Services\ClinicalBackup;
use Illuminate\Console\Command;
use Throwable;

class CreateClinicalBackup extends Command
{
    protected $signature = 'medicore:backup {destination : Nouveau fichier privé .mcb}';

    protected $description = 'Sauvegarder SQLite et les documents médicaux dans une archive chiffrée indépendante';

    public function handle(ClinicalBackup $backup): int
    {
        try {
            $backup->create($this->argument('destination'));
            $this->info('Sauvegarde chiffrée créée. Conserver séparément les clés et tester la restauration.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Sauvegarde échouée. Vérifier maintenance, arrêt des écritures, extensions, clés et chemins privés.');

            return self::FAILURE;
        }
    }
}
