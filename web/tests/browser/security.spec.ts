import { expect, test, type Page } from '@playwright/test';

test('sécurité : changer le mot de passe ferme les deux sessions et refuse l’ancien secret', async ({ page, browser }, info) => {
    const email = `security-${info.project.name}@medicore.test`;
    const oldPassword = 'Recette-Fictive-2026!';
    const newPassword = 'Nouveau-secret-fictif-2026!';
    async function login(target: Page, password: string) {
        await target.goto('/login');
        await target.getByLabel('Adresse e-mail', { exact: true }).fill(email);
        await target.getByLabel('Mot de passe', { exact: true }).fill(password);
        await target.getByRole('button', { name: 'Se connecter', exact: true }).click();
        await expect(target).toHaveURL(/\/dashboard$/);
    }
    const other = await browser.newContext({ baseURL: 'http://127.0.0.1:8123' });
    try {
        const otherPage = await other.newPage();
        await login(page, oldPassword);
        await login(otherPage, oldPassword);
        await page.goto('/settings/password');
        await page.getByLabel('Current password', { exact: true }).fill(oldPassword);
        await page.getByLabel('New password', { exact: true }).fill(newPassword);
        await page.getByLabel('Confirm password', { exact: true }).fill(newPassword);
        await page.getByRole('button', { name: 'Save password', exact: true }).click();
        await expect(page).toHaveURL(/\/login$/);
        await otherPage.goto('/dashboard');
        await expect(otherPage).toHaveURL(/\/login$/);
        await page.getByLabel('Adresse e-mail', { exact: true }).fill(email);
        await page.getByLabel('Mot de passe', { exact: true }).fill(oldPassword);
        await page.getByRole('button', { name: 'Se connecter', exact: true }).click();
        await expect(page.locator('#email-error')).toBeVisible();
        await login(page, newPassword);
    } finally {
        await other.close();
    }
});
