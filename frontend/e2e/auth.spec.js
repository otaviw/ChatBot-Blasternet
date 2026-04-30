import { expect, test } from '@playwright/test';
import { mockAuthApi } from './support/mockAuthApi';

test.describe('Auth flows', () => {
  test('1. login invalido', async ({ page }) => {
    await mockAuthApi(page, { authenticated: false });
    await page.goto('/entrar');

    await page.getByLabel('E-mail').fill('wrong@blasternet.test');
    await page.getByLabel('Senha').fill('wrong-password');
    await page.getByRole('button', { name: 'Entrar no painel' }).click();

    await expect(page.getByText(/Credenciais/i)).toBeVisible();
    await expect(page).toHaveURL(/\/entrar$/);
  });

  test('2. login valido', async ({ page }) => {
    await mockAuthApi(page, { authenticated: false });
    await page.goto('/entrar');

    await page.getByLabel('E-mail').fill('admin@blasternet.test');
    await page.getByLabel('Senha').fill('secret123');
    await page.getByRole('button', { name: 'Entrar no painel' }).click();

    await expect(page).toHaveURL(/\/dashboard$/);
    await expect(page.getByRole('heading', { name: /Painel/i })).toBeVisible();
  });

  test('3. logout', async ({ page }) => {
    await mockAuthApi(page, { authenticated: true });
    await page.goto('/dashboard');

    await page.getByRole('button', { name: 'Abrir menu de perfil' }).click();
    await page.getByRole('button', { name: 'Sair' }).click();

    await expect(page).toHaveURL(/\/entrar$/);
  });

  test('4. rota protegida redireciona', async ({ page }) => {
    await mockAuthApi(page, { authenticated: false });

    await page.goto('/minha-conta/conversas');

    await expect(page).toHaveURL(/\/entrar$/);
    await expect(page.getByRole('button', { name: 'Entrar no painel' })).toBeVisible();
  });

  test('5. refresh mantem sessao', async ({ page }) => {
    await mockAuthApi(page, { authenticated: false });
    await page.goto('/entrar');

    await page.getByLabel('E-mail').fill('admin@blasternet.test');
    await page.getByLabel('Senha').fill('secret123');
    await page.getByRole('button', { name: 'Entrar no painel' }).click();

    await expect(page).toHaveURL(/\/dashboard$/);
    await page.reload();

    await expect(page).toHaveURL(/\/dashboard$/);
    await expect(page.getByRole('heading', { name: /Painel/i })).toBeVisible();
  });
});
