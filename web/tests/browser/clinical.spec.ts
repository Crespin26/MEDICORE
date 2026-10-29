import { test as base, expect, type Page } from '@playwright/test';

// Fail on uncaught React/browser errors as well as missing UI assertions.
const test = base.extend({
    page: async ({ page }, runFixture) => {
        const errors: string[] = [];
        page.on('pageerror', (error) => errors.push(error.message));
        await runFixture(page);
        expect(errors, 'Erreurs JavaScript non traitées').toEqual([]);
    },
});
const password = 'Recette-Fictive-2026!';
async function login(page: Page, role: string) {
    await page.context().clearCookies();
    await page.goto('/login');
    await page.getByLabel('Adresse e-mail', { exact: true }).fill(`${role}@medicore.test`);
    await page.getByLabel('Mot de passe', { exact: true }).fill(password);
    const landing = page.waitForResponse((response) => response.url().endsWith('/dashboard'));
    await page.getByRole('button', { name: 'Se connecter', exact: true }).click();
    expect((await landing).status()).toBe(role === 'staff' ? 403 : 200);
    if (role !== 'staff') await expect(page).toHaveURL(/\/dashboard$/);
}
async function denied(page: Page, url: string) {
    const response = await page.goto(url);
    expect(response?.status(), url).toBe(403);
    await expect(page.locator('body')).not.toContainText('Compte rendu confidentiel de recette');
}

for (const [role, allowed, forbidden] of [
    ['admin', ['/patients', '/staff', '/personnel', '/billing', '/administration/settings'], ['/consultations', '/patients/1/medical-record']],
    ['doctor', ['/patients', '/appointments', '/consultations', '/patients/1/medical-record'], ['/staff', '/billing', '/administration/settings']],
    ['secretary', ['/patients', '/appointments', '/personnel/me'], ['/consultations', '/patients/1/medical-record', '/billing', '/staff']],
    ['cashier', ['/billing', '/personnel/me'], ['/patients', '/appointments', '/consultations', '/staff', '/billing/catalog']],
] as const) {
    test(`${role} : accès autorisés et refus des accès directs`, async ({ page }, info) => {
        await login(page, role);
        if (info.project.name === 'small-screen') {
            await page.getByRole('button', { name: 'Toggle Sidebar', exact: true }).click();
            await expect(page.getByRole('link', { name: role === 'cashier' ? 'Facturation' : 'Patients', exact: true })).toBeVisible();
            await page.keyboard.press('Escape');
        }
        for (const url of allowed) {
            const response = await page.goto(url);
            expect(response?.status(), url).toBe(200);
            await expect(page.getByRole('main')).toHaveCount(1);
            await expect(page.locator('main h1')).toBeVisible();
        }
        for (const url of forbidden) await denied(page, url);
    });
}

test('un autre médecin ne lit pas la consultation du médecin auteur', async ({ page }) => {
    await login(page, 'other-doctor');
    await denied(page, '/consultations/1');
    await page.goto('/patients/1/medical-record');
    await expect(page.locator('main').last()).not.toContainText('Compte rendu confidentiel de recette');
});

test('les comptes suspendus et sans rôle métier restent exclus', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Adresse e-mail', { exact: true }).fill('suspended@medicore.test');
    await page.getByLabel('Mot de passe', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Se connecter', exact: true }).click();
    await expect(page.getByText('These credentials do not match our records.')).toBeVisible();
    await expect(page).toHaveURL(/\/login$/);
    await login(page, 'staff');
    await denied(page, '/patients');
    await denied(page, '/billing');
});

test('secrétariat : création, recherche, doublon et réservation', async ({ page }, info) => {
    const firstName = `Parcours-${info.project.name}`;
    await login(page, 'secretary');
    await page.goto('/patients');
    await page.getByLabel('Prénom', { exact: true }).fill(firstName);
    await page.getByLabel('Nom', { exact: true }).fill('Fictif');
    await page.getByLabel('Date de naissance', { exact: true }).fill('1995-04-12');
    await page.getByRole('button', { name: 'Créer le patient', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('Patient');
    await page.getByLabel('Prénom', { exact: true }).fill(firstName);
    await page.getByLabel('Nom', { exact: true }).fill('Fictif');
    await page.getByLabel('Date de naissance', { exact: true }).fill('1995-04-12');
    await page.getByRole('button', { name: 'Créer le patient', exact: true }).click();
    await expect(page.getByText(/Une fiche porte déjà/)).toBeVisible();
    await page.getByLabel('Nom, prénom, téléphone, e-mail ou référence MC-…').fill(firstName);
    await page.getByRole('button', { name: 'Rechercher', exact: true }).click();
    await expect(page.getByRole('table')).toContainText('1 patient(s) trouvé(s)');
    const date = new Intl.DateTimeFormat('en-CA', { timeZone: 'Africa/Porto-Novo' }).format(new Date(Date.now() + 86_400_000));
    await page.goto(`/appointments?date=${date}`);
    await page.getByLabel('Rechercher un patient (2 caractères minimum)').fill(firstName);
    await page.getByRole('button', { name: new RegExp(`Fictif ${firstName} · MC-`) }).click();
    await page
        .locator('form')
        .filter({ has: page.getByRole('heading', { name: 'Planifier un rendez-vous' }) })
        .getByLabel('Médecin', { exact: true })
        .selectOption({ label: 'Recette doctor' });
    const hour = info.project.name === 'chromium' ? '09' : '10';
    await page.getByLabel('Début', { exact: true }).fill(`${date}T${hour}:00`);
    await page.getByLabel('Fin', { exact: true }).fill(`${date}T${hour}:30`);
    await page.getByRole('button', { name: 'Planifier', exact: true }).click();
    await expect(page.getByRole('table')).toContainText(`Fictif ${firstName}`);
});

test('médecin : validation, correction tracée, information et document privé', async ({ page }, info) => {
    await login(page, 'doctor');
    const appointment = info.project.name === 'chromium' ? 1 : 2;
    await page.goto(`/patients/1/consultations/create?appointment_id=${appointment}`);
    const report = `Compte rendu fictif ${info.project.name}`;
    await page.getByLabel('Motif', { exact: true }).fill('Contrôle de recette fictif');
    await page.getByLabel(/^Compte rendu/).fill(report);
    await page.getByLabel('Enregistrement', { exact: true }).selectOption('validate');
    page.on('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Valider le compte rendu', exact: true }).click();
    await expect(page).toHaveURL(/\/consultations\/\d+$/);
    await expect(page.locator('main').last()).toContainText(report);
    await page.getByRole('button', { name: 'Apporter une correction', exact: true }).click();
    await page.getByLabel(/^Compte rendu/).fill(`${report} corrigé`);
    await page.getByLabel('Motif de la correction').fill('Précision fictive de recette');
    await page.getByRole('button', { name: 'Enregistrer la correction', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Apporter une correction', exact: true })).toBeVisible();
    await expect(page.locator('main').last()).toContainText('Version 2');
    await expect(page.locator('main').last()).toContainText('Précision fictive de recette');
    await page.goto('/patients/1/medical-record');
    const label = `Allergie fictive ${info.project.name}`;
    await page.getByLabel('Libellé', { exact: true }).fill(label);
    await page.getByRole('button', { name: 'Enregistrer', exact: true }).click();
    await expect(page).toHaveURL(/\/medical-entries\/\d+$/);
    await expect(page.locator('main').last()).toContainText(label);
    await page.goto('/patients/1/medical-record');
    await page.getByLabel('Titre du document').fill(`Document fictif ${info.project.name}`);
    // Minimal valid PNG, no patient data.
    const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=', 'base64');
    await page.getByLabel('PDF, JPEG ou PNG · 10 Mo maximum').setInputFiles({ name: 'fictif.png', mimeType: 'image/png', buffer: png });
    await page.getByRole('button', { name: 'Ajouter le document', exact: true }).click();
    const link = page.getByRole('link', { name: `Document fictif ${info.project.name}`, exact: true });
    await expect(link).toBeVisible();
    const downloadPromise = page.waitForEvent('download');
    await link.click();
    const download = await downloadPromise;
    expect(await download.failure()).toBeNull();
    const stream = await download.createReadStream();
    const chunks = [];
    for await (const chunk of stream) chunks.push(Buffer.from(chunk));
    expect(Buffer.concat(chunks)).toEqual(png);
    const documentURL = await link.getAttribute('href');
    await login(page, 'other-doctor');
    await denied(page, documentURL!);
});

test('caissier : émission, paiement partiel puis solde et reçu', async ({ page }) => {
    await login(page, 'cashier');
    await page.goto('/billing/create');
    await page.getByLabel('Rechercher le patient par nom ou référence MC-…').fill('Alice');
    await page.getByRole('button', { name: /Recette Alice · MC-1/ }).click();
    await page.getByLabel('Acte', { exact: true }).selectOption('1');
    await page.getByRole('button', { name: 'Enregistrer le brouillon', exact: true }).click();
    await expect(page).toHaveURL(/\/billing\/invoices\/\d+$/);
    page.on('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Émettre la facture', exact: true }).click();
    await expect(page.locator('main h1')).toContainText('MC-F-');
    await page.getByLabel('Montant encaissé').fill('2000');
    await page.getByRole('button', { name: 'Enregistrer le paiement', exact: true }).click();
    const balance = page.locator('main div.rounded-xl').filter({ has: page.getByText('Solde', { exact: true }) });
    await expect(balance.getByText('3000 XOF', { exact: true })).toBeVisible();
    await page.getByLabel('Montant encaissé').fill('3000');
    await page.getByRole('button', { name: 'Enregistrer le paiement', exact: true }).click();
    await expect(balance.getByText('0 XOF', { exact: true })).toBeVisible();
    const receipt = page.getByRole('link', { name: 'Imprimer le reçu', exact: true }).first();
    await expect(receipt).toBeVisible();
    const response = await page.context().request.get((await receipt.getAttribute('href'))!);
    expect(response.status()).toBe(200);
    expect(await response.text()).toContain('MC-R-');
});

// APP_ENV=e2e deliberately keeps the real CSRF middleware enabled.
test('les écritures sans jeton CSRF sont refusées', async ({ page }) => {
    await login(page, 'secretary');
    const response = await page.context().request.post('/patients', {
        data: { first_name: 'Sans', last_name: 'Jeton', birth_date: '1990-01-01' },
    });
    expect(response.status()).toBe(419);
});

test('la déconnexion retire l’accès à une page protégée', async ({ page }, info) => {
    await login(page, 'secretary');
    if (info.project.name === 'small-screen') await page.getByRole('button', { name: 'Toggle Sidebar', exact: true }).click();
    await page.getByRole('button', { name: /Recette secretary/ }).click();
    await page.getByRole('menuitem', { name: 'Log out', exact: true }).click();
    await expect(page).toHaveURL(/\/login$/);
    await page.goto('/patients');
    await expect(page).toHaveURL(/\/login$/);
});

test('administration : homonyme confirmé, archivage et réactivation', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/patients');
    await page.getByLabel('Prénom', { exact: true }).fill('Alice');
    await page.getByLabel('Nom', { exact: true }).fill('Recette');
    await page.getByLabel('Date de naissance', { exact: true }).fill('1990-01-01');
    await page.getByRole('checkbox', { name: /Je confirme/ }).check();
    await page.getByLabel('Justification de la distinction').fill('Identités distinctes vérifiées pendant la recette');
    await page.getByRole('button', { name: 'Créer le patient', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('Patient créé');
    await page.getByRole('row').filter({ hasText: 'Recette Alice' }).last().getByRole('link', { name: 'Voir la fiche' }).click();
    await expect(page).toHaveURL(/\/patients\/\d+$/);
    const url = page.url();
    page.on('dialog', (dialog) => dialog.accept());
    await page.getByLabel('Motif administratif').fill('Fiche fictive inactive');
    await page.getByRole('button', { name: 'Archiver le patient', exact: true }).click();
    await expect(page.getByText('Fiche archivée : les historiques restent consultables.')).toBeVisible();
    await page.goto('/patients?state=archived');
    await expect(page.getByRole('table')).toContainText('Recette Alice');
    await page.goto(url);
    await page.getByLabel('Motif administratif').fill('Retour fictif pour suivi');
    await page.getByRole('button', { name: 'Réactiver le patient', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('Patient réactivé');
    await expect(page.getByText('Fiche archivée : les historiques restent consultables.')).toHaveCount(0);
});

test('médecins : partage explicite en lecture seule puis révocation', async ({ page }) => {
    await login(page, 'doctor');
    await page.goto('/patients/1/medical-record');
    await page.getByLabel('Médecin destinataire').selectOption({ label: 'Recette other-doctor' });
    await page.getByLabel('Motif du partage').fill('Relais fictif de suivi clinique');
    page.on('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Accorder la lecture', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('Partage en lecture seule accordé');
    await login(page, 'other-doctor');
    await page.goto('/consultations/1');
    await expect(page.locator('main').last()).toContainText('Compte rendu confidentiel de recette');
    await expect(page.getByRole('button', { name: 'Modifier ou valider', exact: true })).toHaveCount(0);
    await page.goto('/patients/1/medical-record');
    await expect(page.getByText(/Éléments partagés avec vous par/)).toContainText('Recette doctor');
    await login(page, 'doctor');
    await page.goto('/patients/1/medical-record');
    await page.getByLabel('Motif de révocation').fill('Fin du relais fictif');
    await page.getByRole('button', { name: 'Révoquer le partage', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('Partage révoqué');
    await login(page, 'other-doctor');
    await denied(page, '/consultations/1');
});
