import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

async function login(page: Page, role: string) {
    await page.goto('/login');
    await page.getByLabel('Adresse e-mail', { exact: true }).fill(`${role}@medicore.test`);
    await page.getByLabel('Mot de passe', { exact: true }).fill('Recette-Fictive-2026!');
    await page.getByRole('button', { name: 'Se connecter', exact: true }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
}

for (const [role, route] of [
    ['guest', '/login'],
    ['admin', '/patients'],
    ['admin', '/patients/1'],
    ['admin', '/administration/settings'],
    ['secretary', '/appointments'],
    ['doctor', '/patients/1/medical-record'],
    ['doctor', '/patients/1/consultations/create'],
    ['cashier', '/billing/create'],
    ['secretary', '/settings/profile'],
] as const) {
    test(`accessibilité : ${role} ${route}`, async ({ page }, info) => {
        if (role !== 'guest') await login(page, role);
        await page.goto(route);
        await expect(page.locator('h1, h2').first()).toBeVisible();
        const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
        await info.attach('accessibility-results', {
            body: JSON.stringify(
                {
                    url: scan.url,
                    timestamp: scan.timestamp,
                    violations: scan.violations,
                    incomplete: scan.incomplete,
                    passedRules: scan.passes.length,
                },
                null,
                2,
            ),
            contentType: 'application/json',
        });
        expect(
            scan.violations.map(({ id, impact, nodes }) => ({
                id,
                impact,
                nodes: nodes.map(({ target, failureSummary }) => ({ target, failureSummary })),
            })),
        ).toEqual([]);
    });
}

test('clavier : connexion sans souris et focus visible', async ({ page }) => {
    await page.goto('/login');
    const email = page.getByLabel('Adresse e-mail', { exact: true });
    const password = page.getByLabel('Mot de passe', { exact: true });
    await expect(email).toBeFocused();
    await page.keyboard.type('secretary@medicore.test');
    await page.keyboard.press('Tab');
    await expect(password).toBeFocused();
    await page.keyboard.type('Recette-Fictive-2026!');
    await page.keyboard.press('Tab');
    await expect(page.getByRole('checkbox', { name: 'Se souvenir de moi' })).toBeFocused();
    await page.keyboard.press('Tab');
    const button = page.getByRole('button', { name: 'Se connecter', exact: true });
    await expect(button).toBeFocused();
    // Focus is indicated by the shared button ring; computed shadow must be present.
    await expect(button).not.toHaveCSS('box-shadow', 'none');
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/\/dashboard$/);
});

test('erreur de connexion : annonce accessible et association au champ', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Adresse e-mail', { exact: true }).fill('absent@medicore.test');
    await page.getByLabel('Mot de passe', { exact: true }).fill('Mot-de-passe-fictif-invalide');
    await page.getByRole('button', { name: 'Se connecter', exact: true }).click();
    const error = page.getByRole('alert');
    await expect(error).toContainText('These credentials do not match our records.');
    const email = page.getByLabel('Adresse e-mail', { exact: true });
    await expect(email).toHaveAttribute('aria-invalid', 'true');
    await expect(email).toHaveAttribute('aria-describedby', 'email-error');
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations).toEqual([]);
});

test('thème sombre : contrastes des paramètres et du planning', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'dark' });
    await login(page, 'secretary');
    for (const route of ['/settings/profile', '/appointments']) {
        await page.goto(route);
        await expect(page.locator('html')).toHaveClass(/dark/);
        const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
        expect(scan.violations).toEqual([]);
    }
});

test('clavier : menu sur petit écran, fermeture et restitution du focus', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await login(page, 'secretary');
    const trigger = page.getByRole('button', { name: 'Toggle Sidebar', exact: true });
    await trigger.focus();
    await page.keyboard.press('Enter');
    const menu = page.getByRole('dialog');
    await expect(menu).toBeVisible();
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations).toEqual([]);
    await page.keyboard.press('Escape');
    await expect(menu).toHaveCount(0);
    await expect(trigger).toBeFocused();
});

test('chargement : indicateur accessible pendant une navigation', async ({ page }, info) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await login(page, 'secretary');
    if (info.project.name === 'small-screen') await page.getByRole('button', { name: 'Toggle Sidebar', exact: true }).click();
    let release = () => {};
    const held = new Promise<void>((resolve) => {
        release = resolve;
    });
    await page.route('**/patients', async (route) => {
        await held;
        await route.continue();
    });
    try {
        await page.getByRole('link', { name: 'Patients', exact: true }).click();
        const progress = page.getByRole('progressbar', { name: 'Chargement de la page' });
        await expect(progress).toBeVisible();
        await expect(progress).not.toHaveAttribute('aria-valuenow');
        await expect(progress.locator('span')).toHaveCSS('animation-name', 'none');
        const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
        expect(scan.violations).toEqual([]);
        release();
        await expect(page).toHaveURL(/\/patients$/);
        await expect(progress).toHaveCount(0);
    } finally {
        release();
    }
});

test('thème système : reste actif après avoir quitté les paramètres', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'light' });
    await login(page, 'secretary');
    await page.goto('/settings/appearance');
    await page.getByRole('button', { name: 'Dark', exact: true }).click();
    await expect(page.locator('html')).toHaveClass(/dark/);
    await page.getByRole('button', { name: 'System', exact: true }).click();
    await expect(page.locator('html')).not.toHaveClass(/dark/);
    // An Inertia visit unmounts useAppearance without reloading the JS application.
    await page.evaluate(() => document.querySelector<HTMLAnchorElement>('a[href$="/settings/profile"]')?.click());
    await expect(page).toHaveURL(/\/settings\/profile$/);
    await page.emulateMedia({ colorScheme: 'dark' });
    await expect(page.locator('html')).toHaveClass(/dark/);
    await page.emulateMedia({ colorScheme: 'light' });
    await expect(page.locator('html')).not.toHaveClass(/dark/);
});

test('stockage navigateur bloqué : navigation et thème restent utilisables', async ({ page }) => {
    await page.addInitScript(() => {
        Object.defineProperty(window, 'localStorage', {
            get() {
                throw new DOMException('Storage blocked', 'SecurityError');
            },
        });
    });
    await login(page, 'secretary');
    await page.goto('/settings/appearance');
    await page.getByRole('button', { name: 'Dark', exact: true }).click();
    await expect(page.locator('html')).toHaveClass(/dark/);
    await page.getByRole('button', { name: 'Light', exact: true }).click();
    await expect(page.locator('html')).not.toHaveClass(/dark/);
});
