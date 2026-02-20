import React, { useEffect, useState } from 'react';
import Layout from '../components/Layout';
import usePageData from '../hooks/usePageData';
import api from '../lib/api';

function EntrarPage() {
  const { data, loading, error } = usePageData('/entrar');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [actionError, setActionError] = useState('');
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (data?.authenticated) {
      window.location.href = '/dashboard';
    }
  }, [data]);

  const handleLogin = async (event) => {
    event.preventDefault();
    setBusy(true);
    setActionError('');

    try {
      await api.get('/sanctum/csrf-cookie');

      await api.post('/login', { email, password });
      window.location.href = '/dashboard';
    } catch (err) {
      setActionError(err.response?.data?.message || 'Falha no login.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Layout>
      <div className="max-w-md mx-auto">
        <h1 className="text-xl font-medium mb-2">Entrar</h1>
        <p className="text-[#706f6c] dark:text-[#A1A09A] text-sm mb-6">
          Use seu email e senha para acessar.
        </p>

        {loading && <p className="text-sm text-[#706f6c]">Carregando...</p>}
        {error && (
          <p className="text-sm text-red-600 dark:text-red-400">
            Erro ao carregar dados de entrada. Tente novamente.
          </p>
        )}
        {actionError && (
          <p className="text-sm text-red-600 dark:text-red-400">
            {actionError}
          </p>
        )}

        {!loading && !error && (
          <>
            <form onSubmit={handleLogin} className="space-y-3 mb-6">
              <label className="block text-sm">
                Email
                <input
                  type="email"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  required
                  className="mt-1 w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
                />
              </label>
              <label className="block text-sm">
                Senha
                <input
                  type="password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  required
                  className="mt-1 w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
                />
              </label>
              <button
                type="submit"
                disabled={busy}
                className="w-full px-4 py-2 rounded bg-[#f53003] text-white disabled:opacity-60"
              >
                {busy ? 'Entrando...' : 'Entrar'}
              </button>
            </form>

            {Array.isArray(data?.demo_accounts) && data.demo_accounts.length > 0 && (
              <section className="border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg p-4">
                <h2 className="text-sm font-medium mb-2">Contas de teste</h2>
                <ul className="text-xs text-[#706f6c] dark:text-[#A1A09A] space-y-1">
                  {data.demo_accounts.map((acc) => (
                    <li key={acc.email}>
                      {acc.label}: {acc.email} / {acc.password}
                    </li>
                  ))}
                </ul>
              </section>
            )}
          </>
        )}
      </div>
    </Layout>
  );
}

export default EntrarPage;
