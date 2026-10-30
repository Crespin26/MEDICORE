<?php

namespace App\Console\Commands;

use App\Support\ProductionConfiguration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class CheckProduction extends Command
{
    protected $signature = 'medicore:production-check';

    protected $description = 'Vérifier la configuration de production sans modifier les données ni afficher les secrets';

    /** Check effective cached configuration, runtime dependencies and migration state. */
    public function handle(): int
    {
        $issues = ProductionConfiguration::issues();
        foreach (['pdo_sqlite', 'sqlite3', 'sodium', 'zip', 'mbstring', 'fileinfo', 'openssl'] as $extension) {
            if (! extension_loaded($extension)) {
                $issues[] = 'Extension PHP requise : '.$extension.'.';
            }
        }
        if (! app()->configurationIsCached() || ! app()->routesAreCached()) {
            $issues[] = 'Construire les caches de configuration et de routes après avoir provisionné les secrets.';
        }
        $cachedConfig = app()->getCachedConfigPath();
        if (is_file($cachedConfig) && (fileperms($cachedConfig) & 0007) !== 0) {
            $issues[] = 'Le cache de configuration contient les secrets et ne doit pas être lisible par les autres utilisateurs système.';
        }
        if (is_file(public_path('hot')) || ! is_file(public_path('build/manifest.json'))) {
            $issues[] = 'Compiler les assets Vite et retirer public/hot.';
        }
        foreach ([storage_path('framework'), storage_path('logs'), base_path('bootstrap/cache')] as $directory) {
            if (! is_dir($directory) || ! is_writable($directory)) {
                $issues[] = 'Répertoires de runtime absents ou non accessibles en écriture au compte courant.';
                break;
            }
        }
        $medical = realpath(config('filesystems.disks.medical.root'));
        $public = realpath(public_path());
        if ($medical === false || ! is_dir($medical) || ! is_writable($medical) || (fileperms($medical) & 0007) !== 0 || ($public !== false && ($medical === $public || str_starts_with($medical, $public.DIRECTORY_SEPARATOR)))) {
            $issues[] = 'Créer un stockage médical privé accessible en écriture et hors du dossier public.';
        }
        try {
            $connection = DB::connection();
            // The recovery tooling is currently qualified for a single SQLite instance only.
            $database = $connection->getDatabaseName();
            if ($connection->getDriverName() !== 'sqlite' || $database === ':memory:' || ! is_file($database)) {
                $issues[] = 'Le profil de déploiement qualifié nécessite une base SQLite persistante sur disque.';
            } else {
                if (! is_writable($database) || ! is_writable(dirname($database))) {
                    $issues[] = 'Le compte courant doit pouvoir écrire la base SQLite et ses fichiers de journal dans son dossier parent.';
                }
                $path = realpath($database);
                if (($public !== false && ($path === $public || str_starts_with($path, $public.DIRECTORY_SEPARATOR))) || (fileperms($database) & 0007) !== 0 || (fileperms(dirname($database)) & 0007) !== 0) {
                    $issues[] = 'La base SQLite doit rester hors du dossier public et inaccessible aux autres utilisateurs système.';
                }
            }
            $migrator = app('migrator');
            $repository = $migrator->getRepository();
            if (! $repository->repositoryExists() || array_diff(array_keys($migrator->getMigrationFiles(database_path('migrations'))), $repository->getRan())) {
                $issues[] = 'Des migrations restent à appliquer sous maintenance après sauvegarde et test de restauration.';
            }
            if (! DB::table('clinic_settings')->where('id', 1)->exists()) {
                $issues[] = 'Les paramètres initiaux de la clinique sont absents.';
            }
        } catch (Throwable) {
            // Connection exceptions may include credentials; never echo the exception here.
            $issues[] = 'Base de données ou schéma inaccessible ; vérifier les accès et les migrations.';
        }
        foreach (array_unique($issues) as $issue) {
            $this->error($issue);
        }
        if ($issues !== []) {
            return self::FAILURE;
        }
        $this->info('Contrôles locaux réussis. Valider aussi HTTPS externe, envoi des mails, sauvegarde distante et supervision.');

        return self::SUCCESS;
    }
}
