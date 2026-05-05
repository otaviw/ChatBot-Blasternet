/**
 * Mocks authentication/authorization endpoints used by frontend E2E tests.
 * Keeps in-memory state to simulate session persistence across requests and refresh.
 */
export async function mockAuthApi(page, options = {}) {
  const validEmail = options.validEmail ?? 'admin@blasternet.test';
  const validPassword = options.validPassword ?? 'secret123';

  const user = options.user ?? {
    id: 10,
    name: 'Usuario E2E',
    email: validEmail,
    role: 'company_admin',
    company_id: 1,
    permissions: null,
  };

  const state = {
    authenticated: Boolean(options.authenticated ?? false),
  };

  await page.route('**/api/**', async (route) => {
    const request = route.request();
    const method = request.method().toUpperCase();
    const url = new URL(request.url());
    const path = url.pathname;

    if (path.endsWith('/api/entrar') && method === 'GET') {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ authenticated: state.authenticated }),
      });
      return;
    }

    if (path.endsWith('/api/me') && method === 'GET') {
      if (!state.authenticated) {
        await route.fulfill({
          status: 401,
          contentType: 'application/json',
          body: JSON.stringify({ message: 'Unauthenticated.' }),
        });
        return;
      }

      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          authenticated: true,
          user,
          role: 'company',
          user_role: user.role,
          companyName: 'Empresa E2E',
        }),
      });
      return;
    }

    if (path.endsWith('/api/sanctum/csrf-cookie') && method === 'GET') {
      await route.fulfill({ status: 204, body: '' });
      return;
    }

    if (path.endsWith('/api/login') && method === 'POST') {
      const payload = request.postDataJSON?.() ?? {};
      const email = String(payload.email ?? '').trim();
      const password = String(payload.password ?? '').trim();

      if (email === validEmail && password === validPassword) {
        state.authenticated = true;
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            authenticated: true,
            user,
            reseller: { slug: null },
          }),
        });
        return;
      }

      await route.fulfill({
        status: 401,
        contentType: 'application/json',
        body: JSON.stringify({
          message: 'Credenciais invalidas.',
          error: 'invalid_credentials',
          code: 401,
        }),
      });
      return;
    }

    if (path.endsWith('/api/logout') && method === 'POST') {
      state.authenticated = false;
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ ok: true }),
      });
      return;
    }

    if (path.endsWith('/api/dashboard') && method === 'GET') {
      if (!state.authenticated) {
        await route.fulfill({
          status: 401,
          contentType: 'application/json',
          body: JSON.stringify({ message: 'Unauthenticated.' }),
        });
        return;
      }

      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          authenticated: true,
          role: 'company',
          user_role: user.role,
          can_manage_users: true,
          companyName: 'Empresa E2E',
        }),
      });
      return;
    }

    if (path.includes('/api/minha-conta/')) {
      if (!state.authenticated) {
        await route.fulfill({
          status: 401,
          contentType: 'application/json',
          body: JSON.stringify({ message: 'Unauthenticated.' }),
        });
        return;
      }
    }

    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ ok: true }),
    });
  });
}
