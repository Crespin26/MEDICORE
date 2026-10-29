<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;
use ZipArchive;

/** Local SQLite recovery archive. Never includes .env or encryption keys. */
class ClinicalBackup
{
    private const MAGIC = "MEDICORE-BACKUP-1\n";

    private const CHUNK = 65536;

    public function create(string $destination): void
    {
        $key = $this->key();
        $this->assertPrivateParent($destination);
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'sqlite' || ! is_file($connection->getDatabaseName())) {
            throw new RuntimeException('Une base SQLite sur disque est requise.');
        }
        $this->assertMaintenance();
        if (! is_dir(dirname($destination)) || is_link(dirname($destination))) {
            throw new RuntimeException('Le dossier de destination privé doit déjà exister.');
        }
        $work = $this->workspace();
        $output = @fopen($destination, 'xb');
        if ($output === false) {
            File::deleteDirectory($work);
            throw new RuntimeException('Destination indisponible ou déjà existante.');
        }
        chmod($destination, 0600);
        try {
            $snapshot = $work.'/database.sqlite';
            $connection->statement('VACUUM INTO '.$connection->getPdo()->quote($snapshot));
            chmod($snapshot, 0600);
            $zip = new ZipArchive;
            if ($zip->open($work.'/payload.zip', ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
                throw new RuntimeException('Création de l’archive impossible.');
            }
            $zip->addFile($snapshot, 'database.sqlite');
            $root = config('filesystems.disks.medical.root');
            if (is_link($root)) {
                throw new RuntimeException('Stockage médical symbolique refusé.');
            }
            if (is_dir($root)) {
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
                foreach ($iterator as $file) {
                    if ($file->isLink() || ! $file->isFile()) {
                        throw new RuntimeException('Fichier médical non régulier refusé.');
                    }
                    $zip->addFile($file->getPathname(), 'medical/'.substr($file->getPathname(), strlen(rtrim($root, '/')) + 1));
                }
            }
            (new ClinicalRecoveryCheck)->verify(new \PDO('sqlite:'.$snapshot), $root);
            $zip->addFromString('manifest.json', json_encode([
                'format' => 1, 'created_at' => now()->toIso8601String(),
                'database_sha256' => hash_file('sha256', $snapshot),
                'app_key_fingerprints' => array_map(fn ($key) => hash('sha256', $key), app('encrypter')->getAllKeys()),
            ], JSON_THROW_ON_ERROR));
            if (! $zip->close()) {
                throw new RuntimeException('Écriture de l’archive impossible.');
            }
            $this->encrypt($work.'/payload.zip', $output, $key);
            fclose($output);
        } catch (Throwable $e) {
            if (is_resource($output)) {
                fclose($output);
            }
            unlink($destination);
            throw $e;
        } finally {
            unset($zip);
            File::deleteDirectory($work);
        }
    }

    /** Restores only to a new directory, with no change to the running application. */
    public function restore(string $archive, string $destination): void
    {
        $key = $this->key();
        $this->assertPrivateParent($destination);
        if (file_exists($destination) || is_link($destination) || ! is_dir(dirname($destination))) {
            throw new RuntimeException('La cible doit être un nouveau dossier dans un parent existant.');
        }
        $work = $this->workspace(dirname($destination));
        try {
            $this->decrypt($archive, $work.'/payload.zip', $key);
            $zip = new ZipArchive;
            if ($zip->open($work.'/payload.zip', ZipArchive::CHECKCONS) !== true) {
                throw new RuntimeException('Archive invalide.');
            }
            $manifest = json_decode($zip->getFromName('manifest.json') ?: '', true, flags: JSON_THROW_ON_ERROR);
            $available = array_map(fn ($key) => hash('sha256', $key), app('encrypter')->getAllKeys());
            if (($manifest['format'] ?? null) !== 1 || ! isset($manifest['app_key_fingerprints'], $manifest['database_sha256']) || array_diff($manifest['app_key_fingerprints'], $available)) {
                throw new RuntimeException('Format invalide ou clés applicatives historiques manquantes.');
            }
            mkdir($work.'/restored', 0700);
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (! is_string($name) || str_contains($name, '\\') || preg_match('~(^/|(^|/)\.\.?(/|$)|\x00)~', $name) || ! preg_match('~^(database\.sqlite|manifest\.json|medical/[a-zA-Z0-9_./-]+)$~D', $name)) {
                    throw new RuntimeException('Chemin d’archive refusé.');
                }
                $target = $work.'/restored/'.$name;
                File::ensureDirectoryExists(dirname($target), 0700);
                $input = $zip->getStream($name);
                $output = fopen($target, 'xb');
                if ($input === false || $output === false) {
                    throw new RuntimeException('Extraction impossible.');
                }
                chmod($target, 0600);
                try {
                    if (stream_copy_to_stream($input, $output) === false) {
                        throw new RuntimeException('Extraction incomplète.');
                    }
                } finally {
                    fclose($input);
                    fclose($output);
                }
            }
            $zip->close();
            $database = $work.'/restored/database.sqlite';
            if (! is_file($database) || ! hash_equals($manifest['database_sha256'], hash_file('sha256', $database))) {
                throw new RuntimeException('Intégrité de la base invalide.');
            }
            $pdo = new \PDO('sqlite:'.$database);
            (new ClinicalRecoveryCheck)->verify($pdo, $work.'/restored/medical');
            unset($pdo);
            // No active database or medical storage is overwritten.
            if (file_exists($destination) || ! rename($work.'/restored', $destination)) {
                throw new RuntimeException('Publication de la restauration impossible.');
            }
        } finally {
            unset($zip);
            File::deleteDirectory($work);
        }
    }

    private function key(): string
    {
        if (! extension_loaded('sodium') || ! extension_loaded('zip')) {
            throw new RuntimeException('Extensions sodium et zip requises.');
        }
        $value = config('backup.key');
        $key = is_string($value) && str_starts_with($value, 'base64:') ? base64_decode(substr($value, 7), true) : false;
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES || in_array($key, app('encrypter')->getAllKeys(), true)) {
            throw new RuntimeException('MEDICORE_BACKUP_KEY doit être une clé base64 de 32 octets distincte des clés applicatives.');
        }

        return $key;
    }

    private function assertPrivateParent(string $destination): void
    {
        $parent = realpath(dirname($destination));
        if ($parent === false) {
            throw new RuntimeException('Dossier parent inexistant.');
        }
        if ((fileperms($parent) & 0077) !== 0) {
            throw new RuntimeException('Le dossier parent doit être privé (permissions 0700).');
        }
        foreach ([public_path(), config('filesystems.disks.medical.root')] as $root) {
            $root = realpath($root);
            if ($root !== false && ($parent === $root || str_starts_with($parent, $root.DIRECTORY_SEPARATOR))) {
                throw new RuntimeException('Destination publique ou dans les documents médicaux refusée.');
            }
        }
    }

    private function assertMaintenance(): void
    {
        if (! app()->isDownForMaintenance()) {
            throw new RuntimeException('Activer la maintenance et arrêter les workers et autres écritures avant la sauvegarde.');
        }
    }

    private function workspace(?string $parent = null): string
    {
        $path = ($parent ?? sys_get_temp_dir()).'/medicore-recovery-'.bin2hex(random_bytes(16));
        if (! mkdir($path, 0700)) {
            throw new RuntimeException('Création du dossier temporaire impossible.');
        }

        return $path;
    }

    private function encrypt(string $source, $output, string $key): void
    {
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        $this->write($output, self::MAGIC.$header);
        $input = fopen($source, 'rb');
        try {
            while (! feof($input)) {
                $plain = fread($input, self::CHUNK);
                if ($plain === false) {
                    throw new RuntimeException('Lecture de sauvegarde impossible.');
                }
                $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $plain);
                $this->write($output, pack('N', strlen($cipher)).$cipher);
            }
            $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, '', '', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
            $this->write($output, pack('N', strlen($cipher)).$cipher);
        } finally {
            fclose($input);
        }
    }

    private function decrypt(string $source, string $destination, string $key): void
    {
        $input = @fopen($source, 'rb');
        if ($input === false) {
            throw new RuntimeException('Sauvegarde illisible.');
        }
        $output = fopen($destination, 'xb');
        chmod($destination, 0600);
        try {
            if ($this->read($input, strlen(self::MAGIC)) !== self::MAGIC) {
                throw new RuntimeException('Format de sauvegarde inconnu.');
            }
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($this->read($input, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES), $key);
            do {
                $length = unpack('Nsize', $this->read($input, 4))['size'];
                if ($length < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $length > self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) {
                    throw new RuntimeException('Bloc de sauvegarde invalide.');
                }
                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $this->read($input, $length));
                if ($result === false) {
                    throw new RuntimeException('Sauvegarde altérée ou clé incorrecte.');
                }
                [$plain, $tag] = $result;
                $this->write($output, $plain);
            } while ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
            if (fread($input, 1) !== '') {
                throw new RuntimeException('Données ajoutées après la sauvegarde.');
            }
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    private function read($stream, int $length): string
    {
        $value = '';
        while (strlen($value) < $length) {
            $chunk = fread($stream, $length - strlen($value));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Sauvegarde tronquée.');
            }
            $value .= $chunk;
        }

        return $value;
    }

    private function write($stream, string $value): void
    {
        while ($value !== '') {
            $written = fwrite($stream, $value);
            if ($written === false || $written === 0) {
                throw new RuntimeException('Écriture de sauvegarde impossible.');
            }
            $value = substr($value, $written);
        }
    }
}
