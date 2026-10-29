import { spawn, spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { chmodSync, mkdirSync, mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';

// Always create a fresh database; never inherit the developer's DB or APP_KEY.
const root = mkdtempSync(path.join(tmpdir(), 'medicore-e2e-'));
chmodSync(root, 0o700);
mkdirSync(path.join(root, 'views'));
const env = {
    ...process.env,
    APP_ENV: 'e2e',
    APP_DEBUG: 'false',
    APP_URL: 'http://127.0.0.1:8123',
    APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
    APP_PREVIOUS_KEYS: '',
    APP_CONFIG_CACHE: path.join(root, 'config.php'),
    APP_ROUTES_CACHE: path.join(root, 'routes.php'),
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: path.join(root, 'database.sqlite'),
    DB_URL: '',
    SESSION_DRIVER: 'database',
    SESSION_DOMAIN: '',
    SESSION_SECURE_COOKIE: 'false',
    SESSION_COOKIE: 'medicore_e2e',
    CACHE_STORE: 'array',
    MAIL_MAILER: 'array',
    QUEUE_CONNECTION: 'sync',
    LOG_CHANNEL: 'stderr',
    VIEW_COMPILED_PATH: path.join(root, 'views'),
    CLINIC_TIMEZONE: 'Africa/Porto-Novo',
    CLINIC_CURRENCY: 'XOF',
    MEDICORE_E2E_DIR: root,
};
const php = process.env.MEDICORE_TEST_PHP_BINARY || 'php';
const options = JSON.parse(process.env.MEDICORE_TEST_PHP_OPTIONS || '[]');
if (!Array.isArray(options) || options.some((value) => typeof value !== 'string')) throw new Error('Invalid PHP options');
let server;
let cleaned = false;
function cleanup() {
    if (cleaned) return;
    cleaned = true;
    rmSync(root, { recursive: true, force: true });
}
try {
    const seed = spawnSync(php, [...options, 'tests/browser/seed.php'], { env, stdio: 'inherit' });
    if (seed.status !== 0) throw new Error('E2E fixture preparation failed');
    server = spawn(php, [...options, '-S', '127.0.0.1:8123', '-t', 'public', 'tests/browser/router.php'], { env, stdio: 'inherit' });
    server.on('error', (error) => {
        console.error(error.message);
        cleanup();
        process.exitCode = 1;
    });
    server.on('exit', (code) => {
        cleanup();
        process.exitCode = code ?? 1;
    });
    for (const signal of ['SIGTERM', 'SIGINT'])
        process.on(signal, () => {
            server.kill(signal);
        });
} catch (error) {
    cleanup();
    throw error;
}
