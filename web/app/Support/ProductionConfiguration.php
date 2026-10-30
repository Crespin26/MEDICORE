<?php

namespace App\Support;

use Illuminate\Encryption\Encrypter;

/** Pure configuration checks, shared by the deployment command and regression tests. */
class ProductionConfiguration
{
    /** Return actionable failures without disclosing credentials or encryption keys. */
    public static function issues(): array
    {
        $issues = [];
        $require = function (bool $condition, string $message) use (&$issues): void {
            if (! $condition) {
                $issues[] = $message;
            }
        };
        $url = parse_url((string) config('app.url'));
        $require(config('app.env') === 'production', 'APP_ENV doit être production.');
        $require(config('app.debug') === false, 'APP_DEBUG doit être false.');
        $require(is_array($url) && ($url['scheme'] ?? '') === 'https' && ! empty($url['host']) && ! isset($url['user']) && ! isset($url['pass']) && ! isset($url['query']) && ! isset($url['fragment']) && in_array($url['path'] ?? '', ['', '/'], true), 'APP_URL doit être une origine HTTPS sans identifiants, sous-chemin ni paramètres.');
        $require(config('session.secure') === true && config('session.http_only') === true, 'Les cookies de session doivent être Secure et HttpOnly.');
        $require(config('session.encrypt') === true, 'SESSION_ENCRYPT doit être true.');
        $require(in_array(config('session.same_site'), ['lax', 'strict'], true), 'SESSION_SAME_SITE doit être lax ou strict.');
        $require(! in_array(config('session.driver'), ['array', 'cookie'], true), 'Utiliser un stockage de session persistant côté serveur.');
        $require(config('cache.default') !== 'array', 'Utiliser un cache persistant pour les limitations de requêtes.');
        $channel = config('logging.default');
        $require(in_array($channel, ['daily', 'single'], true), 'Le profil serveur utilise un journal local daily ou single.');
        $require(in_array(config('logging.channels.'.$channel.'.level'), ['notice', 'warning', 'error', 'critical', 'alert', 'emergency'], true), 'LOG_LEVEL doit limiter les journaux de production (warning conseillé).');
        $require(in_array(config('mail.default'), ['smtp', 'ses', 'postmark', 'resend', 'sendmail'], true), 'Configurer un transport mail réel sans repli vers les journaux.');
        $require(array_intersect(['*', '**', 'REMOTE_ADDR'], config('app.trusted_proxies', [])) === [], 'TRUSTED_PROXIES doit lister uniquement les adresses des proxies de confiance.');

        $appKey = config('app.key');
        $decode = fn ($key) => is_string($key) && str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
        $require(is_string($appKey) && Encrypter::supported((string) $decode($appKey), config('app.cipher')), 'APP_KEY doit être une clé valide ; conserver les clés des données existantes.');
        $backup = config('backup.key');
        $backupKey = is_string($backup) && str_starts_with($backup, 'base64:') ? base64_decode(substr($backup, 7), true) : false;
        $appKeys = array_map($decode, [$appKey, ...config('app.previous_keys', [])]);
        $require(is_string($backupKey) && strlen($backupKey) === 32 && ! in_array($backupKey, $appKeys, true), 'Provisionner une MEDICORE_BACKUP_KEY indépendante de 32 octets en base64.');

        return $issues;
    }
}
