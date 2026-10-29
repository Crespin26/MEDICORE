import { spawn } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { mkdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { setTimeout as delay } from 'node:timers/promises';

const mobile = process.argv.includes('--mobile');
const concurrency = Number(process.env.BENCH_CONCURRENCY || 5);
const count = Number(process.env.BENCH_REQUESTS || 100);
if (!Number.isInteger(concurrency) || concurrency < 1 || concurrency > 20 || !Number.isInteger(count) || count < 1 || count > 5000)
    throw new Error('Invalid local benchmark limits');
const marker = randomBytes(16).toString('hex');
const base = 'http://127.0.0.1:8123';
const env = { ...process.env, MEDICORE_E2E_PROBE_ID: marker, MEDICORE_BENCH_DATA: mobile ? '' : '1' };
let log = '';
const server = spawn(process.execPath, ['tests/browser/server.mjs'], { env, stdio: ['ignore', 'pipe', 'pipe'] });
server.stdout.on('data', (chunk) => {
    log = (log + chunk).slice(-10000);
});
server.stderr.on('data', (chunk) => {
    log = (log + chunk).slice(-10000);
});
try {
    let ready = false;
    for (let i = 0; i < 300; i++) {
        if (server.exitCode !== null) throw new Error('Fixture server failed');
        try {
            const response = await fetch(`${base}/login`, { signal: AbortSignal.timeout(1000) });
            if (response.headers.get('X-MediCore-Fixture') === marker) {
                ready = true;
                break;
            }
        } catch {
            /* Wait only for this new fixture, never reuse an existing server. */
        }
        await delay(100);
    }
    if (!ready) throw new Error('Isolated fixture was not ready');
    if (mobile) {
        const flutter = process.env.MEDICORE_FLUTTER_BINARY || 'flutter';
        const child = spawn(flutter, ['test', 'test/live_api_test.dart', '--dart-define=MEDICORE_LIVE_API_URL=http://127.0.0.1:8123/api/v1'], {
            cwd: path.resolve('../mobile'),
            env: process.env,
            stdio: 'inherit',
        });
        const code = await new Promise((resolve, reject) => {
            child.on('error', reject);
            child.on('exit', resolve);
        });
        if (code !== 0) throw new Error('Live Flutter API test failed');
    } else {
        const tokens = [];
        for (const role of ['admin', 'doctor', 'other-doctor', 'secretary']) {
            const response = await fetch(`${base}/api/v1/session`, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({ email: `${role}@medicore.test`, password: 'Recette-Fictive-2026!', device_name: 'Local load fixture' }),
            });
            if (response.status !== 201) throw new Error('Fixture authentication failed');
            tokens.push((await response.json()).data.token);
        }
        const yesterday = new Intl.DateTimeFormat('en-CA', { timeZone: 'Africa/Porto-Novo' }).format(new Date(Date.now() - 86400000));
        const routes = ['/patients?search=charge', `/appointments?date=${yesterday}`];
        const timings = [];
        const statuses = {};
        let next = 0;
        const started = performance.now();
        await Promise.all(
            Array.from({ length: concurrency }, async () => {
                while (next < count) {
                    const id = next++;
                    const start = performance.now();
                    const response = await fetch(`${base}/api/v1${routes[id % routes.length]}`, {
                        headers: { Accept: 'application/json', Authorization: `Bearer ${tokens[id % tokens.length]}` },
                        signal: AbortSignal.timeout(20000),
                    });
                    const body = await response.json();
                    if (response.status === 200 && !Array.isArray(body.data ?? body.appointments?.data)) throw new Error('Unexpected response shape');
                    statuses[response.status] = (statuses[response.status] || 0) + 1;
                    timings.push(performance.now() - start);
                }
            }),
        );
        const duration = (performance.now() - started) / 1000;
        timings.sort((a, b) => a - b);
        const percentile = (p) => Number(timings[Math.min(timings.length - 1, Math.ceil(p * timings.length) - 1)].toFixed(2));
        const report = {
            recordedAt: new Date().toISOString(),
            scope: 'Local synthetic HTTP baseline; PHP development server, SQLite; not production capacity',
            patients: 1001,
            appointments: 10004,
            concurrency,
            requests: count,
            statuses,
            durationSeconds: Number(duration.toFixed(2)),
            requestsPerSecond: Number((count / duration).toFixed(2)),
            latencyMs: { p50: percentile(0.5), p95: percentile(0.95), max: percentile(1) },
        };
        mkdirSync('test-results', { recursive: true });
        writeFileSync('test-results/performance.json', JSON.stringify(report, null, 2));
        console.log(JSON.stringify(report, null, 2));
        if (statuses[200] !== count) throw new Error('Unexpected HTTP status in baseline');
    }
} catch (error) {
    console.error(log);
    throw error;
} finally {
    const exited = new Promise((resolve) => server.once('exit', resolve));
    if (server.exitCode === null) {
        server.kill('SIGTERM');
        await exited;
    }
}
