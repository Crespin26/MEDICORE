<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use PDO;
use RuntimeException;

/** Verify keys and documents against the snapshot, without changing application connections. */
class ClinicalRecoveryCheck
{
    /** Contrôle le schéma SQLite, le déchiffrement des champs et l’intégrité des documents du snapshot. */
    public function verify(PDO $pdo, string $medicalRoot): void
    {
        if ($pdo->query('PRAGMA integrity_check')->fetchColumn() !== 'ok' || $pdo->query('PRAGMA foreign_key_check')->fetch() !== false) {
            throw new RuntimeException('La base restaurée est incohérente.');
        }
        $consultationsEncrypted = $pdo->query("SELECT COUNT(*) FROM migrations WHERE migration = '2026_10_29_000001_encrypt_consultation_content'")->fetchColumn() > 0;
        $columns = [
            'medical_record_entries' => ['label', 'details'],
            'medical_entry_revisions' => ['snapshot', 'correction_reason'],
            'medical_documents' => ['title', 'original_name'],
        ];
        if ($consultationsEncrypted) {
            $columns['consultations'] = ['reason', 'observations', 'report', 'vitals'];
            $columns['consultation_revisions'] = ['snapshot', 'correction_reason'];
        }
        foreach (['patient_events', 'clinical_shares'] as $table) {
            if ($pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = '".$table."'")->fetchColumn() > 0) {
                $columns[$table] = ['reason'];
            }
        }
        foreach ($columns as $table => $fields) {
            $query = $pdo->query('SELECT '.implode(', ', $fields).' FROM '.$table);
            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                foreach ($fields as $field) {
                    if ($row[$field] !== null) {
                        Crypt::decryptString($row[$field]);
                    }
                }
            }
        }
        $query = $pdo->query('SELECT path, sha256, size FROM medical_documents');
        while ($document = $query->fetch(PDO::FETCH_ASSOC)) {
            $path = $document['path'];
            if (! is_string($path) || $path !== basename($path) || str_contains($path, '\\') || in_array($path, ['.', '..'], true)) {
                throw new RuntimeException('Chemin de document invalide.');
            }
            $file = $medicalRoot.'/'.$path;
            if (! is_file($file) || is_link($file)) {
                throw new RuntimeException('Document médical manquant.');
            }
            $content = Crypt::decryptString(file_get_contents($file));
            if (strlen($content) !== (int) $document['size'] || ! hash_equals($document['sha256'], hash('sha256', $content))) {
                throw new RuntimeException('Document médical altéré.');
            }
        }
    }
}
