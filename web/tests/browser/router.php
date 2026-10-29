<?php

use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;

if (PHP_SAPI !== 'cli-server' || getenv('APP_ENV') !== 'e2e' || ! getenv('MEDICORE_E2E_DIR')) {
    http_response_code(404);
    exit;
}
$public = realpath(__DIR__.'/../../public');
$file = realpath($public.parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if ($file !== false && str_starts_with($file, $public.DIRECTORY_SEPARATOR) && is_file($file)) {
    return false;
}
if (getenv('MEDICORE_E2E_PROBE_ID')) {
    header('X-MediCore-Fixture: '.getenv('MEDICORE_E2E_PROBE_ID'));
}
define('LARAVEL_START', microtime(true));
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->booted(function () use ($app) {
    $root = getenv('MEDICORE_E2E_DIR');
    config(['filesystems.disks.medical.root' => $root.'/medical']);
    $app->make(Vite::class)->useHotFile($root.'/hot');
});
$app->handleRequest(Request::capture());
