import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: './tests/browser',
    fullyParallel: false,
    workers: 1,
    forbidOnly: !!process.env.CI,
    retries: 0,
    timeout: 30_000,
    reporter: 'list',
    use: {
        baseURL: 'http://127.0.0.1:8123',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [
        { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
        { name: 'small-screen', use: { ...devices['Desktop Chrome'], viewport: { width: 390, height: 844 } } },
    ],
    webServer: {
        command: 'node tests/browser/server.mjs',
        url: 'http://127.0.0.1:8123/login',
        reuseExistingServer: false,
        timeout: 60_000,
        gracefulShutdown: { signal: 'SIGTERM', timeout: 5000 },
        stdout: 'pipe',
        stderr: 'pipe',
    },
});
