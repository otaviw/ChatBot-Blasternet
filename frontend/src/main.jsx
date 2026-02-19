import React, { useEffect, useState } from 'react';
import ReactDOM from 'react-dom/client';
import axios from 'axios';
import './app.css';

const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE ?? '/api',
  withCredentials: true,
  headers: { Accept: 'application/json' },
});

const DAY_KEYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
const DAY_LABELS = {
  monday: 'Segunda',
  tuesday: 'Terca',
  wednesday: 'Quarta',
  thursday: 'Quinta',
  friday: 'Sexta',
  saturday: 'Sabado',
  sunday: 'Domingo',
};

const DEFAULT_SETTINGS = {
  is_active: true,
  timezone: 'America/Sao_Paulo',
  welcome_message: 'Oi. Como posso ajudar?',
  fallback_message: 'Nao entendi sua mensagem. Pode reformular?',
  out_of_hours_message: 'Estamos fora do horario de atendimento no momento.',
  business_hours: {
    monday: { enabled: true, start: '08:00', end: '18:00' },
    tuesday: { enabled: true, start: '08:00', end: '18:00' },
    wednesday: { enabled: true, start: '08:00', end: '18:00' },
    thursday: { enabled: true, start: '08:00', end: '18:00' },
    friday: { enabled: true, start: '08:00', end: '18:00' },
    saturday: { enabled: false, start: '', end: '' },
    sunday: { enabled: false, start: '', end: '' },
  },
  keyword_replies: [],
};

function normalizeSettings(input) {
  const merged = {
    ...DEFAULT_SETTINGS,
    ...(input ?? {}),
    business_hours: {
      ...DEFAULT_SETTINGS.business_hours,
      ...((input ?? {}).business_hours ?? {}),
    },
  };

  return {
    ...merged,
    keyword_replies: Array.isArray(merged.keyword_replies) ? merged.keyword_replies : [],
  };
}

function Layout({ children, role, companyName, onLogout }) {
  const isLogged = Boolean(role);

  const handleLogout = (event) => {
    if (!onLogout) return;
    event.preventDefault();
    onLogout();
  };

  return (
    <div className="min-h-screen bg-[#FDFDFC] dark:bg-[#0a0a0a] text-[#1b1b18]">
      <header className="border-b border-[#e3e3e0] dark:border-[#3E3E3A] bg-white dark:bg-[#161615]">
        <div className="max-w-6xl mx-auto px-4 py-3 flex items-center justify-between">
          <a
            href={isLogged ? '/dashboard' : '/entrar'}
            className="font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
          >
            Blasternet ChatBot
            {role === 'company' && companyName ? ` - ${companyName}` : null}
          </a>
          {isLogged && (
            <nav className="flex items-center gap-4 text-sm">
              {role === 'admin' && (
                <>
                  <a
                    href="/admin/empresas"
                    className="text-[#706f6c] dark:text-[#A1A09A] hover:text-[#1b1b18] dark:hover:text-white"
                  >
                    Empresas
                  </a>
                  <a
                    href="/admin/simulador"
                    className="text-[#706f6c] dark:text-[#A1A09A] hover:text-[#1b1b18] dark:hover:text-white"
                  >
                    Simulador
                  </a>
                  <a
                    href="/admin/conversas"
                    className="text-[#706f6c] dark:text-[#A1A09A] hover:text-[#1b1b18] dark:hover:text-white"
                  >
                    Inbox
                  </a>
                  <a
                    href="/admin/usuarios"
                    className="text-[#706f6c] dark:text-[#A1A09A] hover:text-[#1b1b18] dark:hover:text-white"
                  >
                    Usuarios
                  </a>
                </>
              )}
              {role === 'company' && (
                <>
                  <a
                    href="/minha-conta/bot"
                    className="text-[#706f6c] dark:text-[#A1A09A] hover:text-[#1b1b18] dark:hover:text-white"
                  >
                    Config. do bot
                  </a>
                  <a
                    href="/minha-conta/simulador"
                    className="text-[#706f6c] dark:text-[#A1A09A] hover:text-[#1b1b18] dark:hover:text-white"
                  >
                    Simulador
                  </a>
                  <a
                    href="/minha-conta/conversas"
                    className="text-[#706f6c] dark:text-[#A1A09A] hover:text-[#1b1b18] dark:hover:text-white"
                  >
                    Inbox
                  </a>
                </>
              )}
              <a
                href="/entrar"
                onClick={handleLogout}
                className="text-[#706f6c] dark:text-[#A1A09A] hover:text-[#1b1b18] dark:hover:text-white"
              >
                Sair
              </a>
            </nav>
          )}
        </div>
      </header>

      <main className="max-w-6xl mx-auto px-4 py-8">{children}</main>
    </div>
  );
}

function usePageData(url, initial = null) {
  const [data, setData] = useState(initial);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    let canceled = false;
    setLoading(true);
    api
      .get(url)
      .then((response) => {
        if (canceled) return;
        const payload = response.data;
        if (payload?.redirect) {
          window.location.href = payload.redirect;
          return;
        }
        setData(payload);
        setError(null);
      })
      .catch((err) => {
        if (canceled) return;
        const redirect = err.response?.data?.redirect;
        if (redirect) {
          window.location.href = redirect;
          return;
        }
        setError(err);
      })
      .finally(() => {
        if (!canceled) {
          setLoading(false);
        }
      });

    return () => {
      canceled = true;
    };
  }, [url]);

  return { data, loading, error };
}

function useLogout() {
  const [error, setError] = useState(null);

  const logout = async () => {
    try {
      await api.post('/logout');
      window.location.href = '/entrar';
    } catch (err) {
      setError(err);
    }
  };

  return { logout, error };
}

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

function DashboardPage() {
  const { data, loading, error } = usePageData('/dashboard');
  const { logout } = useLogout();

  if (loading) {
    return (
      <Layout>
        <p className="text-sm text-[#706f6c]">Carregando dashboard...</p>
      </Layout>
    );
  }

  if (error || !data?.authenticated) {
    return (
      <Layout>
        <p className="text-sm text-red-600 dark:text-red-400">
          Nao foi possivel carregar o dashboard.
        </p>
      </Layout>
    );
  }

  if (data.role === 'admin') {
    return (
      <Layout role="admin" onLogout={logout}>
        <h1 className="text-xl font-medium mb-2">Dashboard - Minha empresa</h1>
        <p className="text-[#706f6c] dark:text-[#A1A09A] text-sm mb-6">
          Voce esta como administrador. Aqui voce gerencia empresas, usos e informacoes.
        </p>

        <ul className="space-y-2 text-sm">
          <li>
            <a href="/admin/empresas" className="text-[#f53003] dark:text-[#FF4433] underline underline-offset-2">
              Empresas
            </a>{' '}
            - listar, ver informacoes e uso de cada uma
          </li>
          <li>
            <a href="/admin/simulador" className="text-[#f53003] dark:text-[#FF4433] underline underline-offset-2">
              Simulador
            </a>{' '}
            - testar resposta do bot sem Meta
          </li>
          <li>
            <a href="/admin/conversas" className="text-[#f53003] dark:text-[#FF4433] underline underline-offset-2">
              Inbox
            </a>{' '}
            - ver historico de mensagens
          </li>
          <li>
            <a href="/admin/usuarios" className="text-[#f53003] dark:text-[#FF4433] underline underline-offset-2">
              Usuarios
            </a>{' '}
            - criar e gerenciar acessos
          </li>
        </ul>
      </Layout>
    );
  }

  return (
    <Layout role="company" companyName={data.companyName} onLogout={logout}>
      <h1 className="text-xl font-medium mb-2">Dashboard - {data.companyName ?? 'Empresa'}</h1>
      <p className="text-[#706f6c] dark:text-[#A1A09A] text-sm mb-6">
        Aqui voce gerencia como o bot funciona: respostas, horarios e demais configuracoes.
      </p>

      <ul className="space-y-2 text-sm">
        <li>
          <a href="/minha-conta/bot" className="text-[#f53003] dark:text-[#FF4433] underline underline-offset-2">
            Configuracoes do bot
          </a>{' '}
          - respostas, horarios, etc.
        </li>
        <li>
          <a href="/minha-conta/simulador" className="text-[#f53003] dark:text-[#FF4433] underline underline-offset-2">
            Simulador
          </a>{' '}
          - testar conversa localmente
        </li>
        <li>
          <a href="/minha-conta/conversas" className="text-[#f53003] dark:text-[#FF4433] underline underline-offset-2">
            Inbox
          </a>{' '}
          - acompanhar conversas recebidas
        </li>
      </ul>
    </Layout>
  );
}

function AdminCompaniesPage() {
  const { data, loading, error } = usePageData('/admin/empresas');
  const { logout } = useLogout();
  const [newCompany, setNewCompany] = useState({
    name: '',
    meta_phone_number_id: '',
    meta_access_token: '',
  });
  const [createBusy, setCreateBusy] = useState(false);
  const [createError, setCreateError] = useState('');
  const [createSuccess, setCreateSuccess] = useState('');

  const handleCreateCompany = async (event) => {
    event.preventDefault();
    setCreateBusy(true);
    setCreateError('');
    setCreateSuccess('');

    try {
      const payload = {
        name: newCompany.name,
        meta_phone_number_id: newCompany.meta_phone_number_id || null,
        meta_access_token: newCompany.meta_access_token || null,
      };
      const response = await api.post('/admin/empresas', payload);
      const created = response.data?.company;
      setCreateSuccess(`Empresa criada: ${created?.name ?? payload.name}`);
      setNewCompany({ name: '', meta_phone_number_id: '', meta_access_token: '' });
      setTimeout(() => window.location.reload(), 400);
    } catch (err) {
      setCreateError(err.response?.data?.message || 'Falha ao criar empresa.');
    } finally {
      setCreateBusy(false);
    }
  };

  if (loading) {
    return (
      <Layout role="admin" onLogout={logout}>
        <p className="text-sm text-[#706f6c]">Carregando empresas...</p>
      </Layout>
    );
  }

  if (error || !data?.authenticated) {
    return (
      <Layout>
        <p className="text-sm text-red-600 dark:text-red-400">Nao foi possivel carregar as empresas.</p>
      </Layout>
    );
  }

  const companies = data.companies ?? [];

  return (
    <Layout role="admin" onLogout={logout}>
      <h1 className="text-xl font-medium mb-2">Empresas</h1>
      <p className="text-[#706f6c] dark:text-[#A1A09A] text-sm mb-6">
        Lista de empresas com acesso. Clique para ver informacoes e uso.
      </p>

      <section className="mb-8 border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg p-4">
        <h2 className="font-medium mb-3">Criar empresa</h2>
        <form onSubmit={handleCreateCompany} className="grid grid-cols-1 md:grid-cols-2 gap-3">
          <label className="block text-sm md:col-span-2">
            Nome da empresa
            <input
              type="text"
              value={newCompany.name}
              onChange={(e) => setNewCompany((p) => ({ ...p, name: e.target.value }))}
              required
              className="mt-1 w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
            />
          </label>

          <label className="block text-sm">
            Meta Phone Number ID
            <input
              type="text"
              value={newCompany.meta_phone_number_id}
              onChange={(e) => setNewCompany((p) => ({ ...p, meta_phone_number_id: e.target.value }))}
              className="mt-1 w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
            />
          </label>

          <label className="block text-sm">
            Meta Access Token
            <input
              type="password"
              value={newCompany.meta_access_token}
              onChange={(e) => setNewCompany((p) => ({ ...p, meta_access_token: e.target.value }))}
              className="mt-1 w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
            />
          </label>

          <div className="md:col-span-2">
            <button
              type="submit"
              disabled={createBusy}
              className="px-4 py-2 rounded bg-[#f53003] text-white disabled:opacity-60"
            >
              {createBusy ? 'Criando...' : 'Criar empresa'}
            </button>
          </div>
        </form>
        {createError && <p className="text-sm text-red-600 mt-2">{createError}</p>}
        {createSuccess && <p className="text-sm text-green-700 mt-2">{createSuccess}</p>}
      </section>

      {!companies.length ? (
        <p className="text-sm text-[#706f6c]">Nenhuma empresa cadastrada.</p>
      ) : (
        <ul className="border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg divide-y divide-[#e3e3e0] dark:divide-[#3E3E3A] overflow-hidden">
          {companies.map((company) => (
            <li key={company.id}>
              <a href={`/admin/empresas/${company.id}`} className="block px-4 py-3 hover:bg-[#FDFDFC] dark:hover:bg-[#161615]">
                <span className="font-medium">{company.name}</span>
                <span className="text-sm text-[#706f6c] dark:text-[#A1A09A] ml-2">
                  - {company.conversations_count ?? 0} conversa(s)
                </span>
                <span className="text-xs text-[#706f6c] dark:text-[#A1A09A] ml-2">
                  | bot: {company.bot_setting ? 'configurado' : 'padrao'}
                </span>
              </a>
            </li>
          ))}
        </ul>
      )}
    </Layout>
  );
}

function AdminCompanyShowPage({ companyId }) {
  const { data, loading, error } = usePageData(`/admin/empresas/${companyId}`);
  const { logout } = useLogout();
  const [settings, setSettings] = useState(DEFAULT_SETTINGS);
  const [saveState, setSaveState] = useState('idle');
  const [saveError, setSaveError] = useState('');
  const [companyForm, setCompanyForm] = useState({
    name: '',
    meta_phone_number_id: '',
    meta_access_token: '',
  });
  const [companySaveState, setCompanySaveState] = useState('idle');
  const [companySaveError, setCompanySaveError] = useState('');

  useEffect(() => {
    if (!data?.company) return;
    setSettings(normalizeSettings(data.company.bot_setting));
    setCompanyForm({
      name: data.company.name ?? '',
      meta_phone_number_id: data.company.meta_phone_number_id ?? '',
      meta_access_token: '',
    });
  }, [data]);

  const updateMessageField = (key, value) => {
    setSettings((prev) => ({ ...prev, [key]: value }));
  };

  const updateDay = (day, patch) => {
    setSettings((prev) => ({
      ...prev,
      business_hours: {
        ...prev.business_hours,
        [day]: {
          ...prev.business_hours[day],
          ...patch,
        },
      },
    }));
  };

  const updateKeyword = (index, key, value) => {
    setSettings((prev) => {
      const next = [...prev.keyword_replies];
      next[index] = { ...next[index], [key]: value };
      return { ...prev, keyword_replies: next };
    });
  };

  const addKeywordReply = () => {
    setSettings((prev) => ({
      ...prev,
      keyword_replies: [...prev.keyword_replies, { keyword: '', reply: '' }],
    }));
  };

  const removeKeywordReply = (index) => {
    setSettings((prev) => ({
      ...prev,
      keyword_replies: prev.keyword_replies.filter((_, i) => i !== index),
    }));
  };

  const saveSettings = async (event) => {
    event.preventDefault();
    setSaveState('saving');
    setSaveError('');

    try {
      const payload = {
        ...settings,
        keyword_replies: settings.keyword_replies.filter((item) => item.keyword?.trim() && item.reply?.trim()),
      };
      const response = await api.put(`/admin/empresas/${companyId}/bot`, payload);
      setSettings(normalizeSettings(response.data?.settings));
      setSaveState('saved');
      setTimeout(() => setSaveState('idle'), 2500);
    } catch (err) {
      setSaveState('error');
      setSaveError(err.response?.data?.message || 'Falha ao salvar configuracoes.');
    }
  };

  const saveCompanyData = async (event) => {
    event.preventDefault();
    setCompanySaveState('saving');
    setCompanySaveError('');

    try {
      const payload = {
        name: companyForm.name,
        meta_phone_number_id: companyForm.meta_phone_number_id || null,
      };
      if (companyForm.meta_access_token.trim() !== '') {
        payload.meta_access_token = companyForm.meta_access_token;
      }
      await api.put(`/admin/empresas/${companyId}`, payload);
      setCompanySaveState('saved');
      setCompanyForm((prev) => ({ ...prev, meta_access_token: '' }));
      setTimeout(() => {
        setCompanySaveState('idle');
        window.location.reload();
      }, 600);
    } catch (err) {
      setCompanySaveState('error');
      setCompanySaveError(err.response?.data?.message || 'Falha ao salvar dados da empresa.');
    }
  };

  if (loading) {
    return (
      <Layout role="admin" onLogout={logout}>
        <p className="text-sm text-[#706f6c]">Carregando empresa...</p>
      </Layout>
    );
  }

  if (error || !data?.authenticated || !data.company) {
    return (
      <Layout>
        <p className="text-sm text-red-600 dark:text-red-400">Nao foi possivel carregar a empresa.</p>
      </Layout>
    );
  }

  const company = data.company;
  const setting = company.bot_setting;

  return (
    <Layout role="admin" onLogout={logout}>
      <div className="mb-4">
        <a href="/admin/empresas" className="text-sm text-[#706f6c] dark:text-[#A1A09A] hover:underline">
        ← Empresas
        </a>
      </div>
      <h1 className="text-xl font-medium mb-2">{company.name}</h1>
      <p className="text-[#706f6c] dark:text-[#A1A09A] text-sm mb-6">Informacoes e uso da empresa.</p>

      <section className="mb-8">
        <h2 className="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A] mb-2">Informacoes</h2>
        <ul className="text-sm space-y-1">
          <li>ID: {company.id}</li>
          <li>Nome: {company.name}</li>
          <li>Meta Phone Number ID: {company.meta_phone_number_id ? company.meta_phone_number_id : '-'}</li>
          <li>Token configurado: {company.has_meta_credentials ? 'Sim' : 'Nao'}</li>
          <li>Bot ativo: {setting?.is_active ? 'Sim' : 'Nao'}</li>
          <li>Timezone: {setting?.timezone ?? 'America/Sao_Paulo'}</li>
        </ul>
      </section>

      <section className="mb-8 border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg p-4">
        <h2 className="font-medium mb-3">Dados da empresa (admin)</h2>
        <form onSubmit={saveCompanyData} className="grid grid-cols-1 md:grid-cols-2 gap-3">
          <label className="block text-sm md:col-span-2">
            Nome da empresa
            <input
              type="text"
              value={companyForm.name}
              onChange={(e) => setCompanyForm((p) => ({ ...p, name: e.target.value }))}
              required
              className="mt-1 w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
            />
          </label>

          <label className="block text-sm">
            Meta Phone Number ID
            <input
              type="text"
              value={companyForm.meta_phone_number_id}
              onChange={(e) => setCompanyForm((p) => ({ ...p, meta_phone_number_id: e.target.value }))}
              className="mt-1 w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
            />
          </label>

          <label className="block text-sm">
            Novo Meta Access Token (opcional)
            <input
              type="password"
              value={companyForm.meta_access_token}
              onChange={(e) => setCompanyForm((p) => ({ ...p, meta_access_token: e.target.value }))}
              className="mt-1 w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
            />
          </label>

          <div className="md:col-span-2">
            <button
              type="submit"
              disabled={companySaveState === 'saving'}
              className="px-4 py-2 rounded bg-[#f53003] text-white disabled:opacity-60"
            >
              {companySaveState === 'saving' ? 'Salvando dados...' : 'Salvar dados da empresa'}
            </button>
          </div>
        </form>
        {companySaveState === 'saved' && <p className="text-sm text-green-700 mt-2">Dados salvos.</p>}
        {companySaveState === 'error' && <p className="text-sm text-red-600 mt-2">{companySaveError}</p>}
      </section>

      <section className="mb-8">
        <h2 className="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A] mb-2">Regras do bot</h2>
        {!setting ? (
          <p className="text-sm text-[#706f6c]">Empresa ainda usando configuracao padrao.</p>
        ) : (
          <ul className="text-sm space-y-1">
            <li>Mensagem de boas-vindas: {setting.welcome_message || '-'}</li>
            <li>Mensagem fallback: {setting.fallback_message || '-'}</li>
            <li>Mensagem fora de horario: {setting.out_of_hours_message || '-'}</li>
            <li>Respostas por palavra-chave: {Array.isArray(setting.keyword_replies) ? setting.keyword_replies.length : 0}</li>
          </ul>
        )}
      </section>

      <section className="mb-8">
        <h2 className="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A] mb-2">Editar configuracoes (admin)</h2>
        <form onSubmit={saveSettings} className="space-y-8 max-w-4xl">
          <section className="border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg p-4 space-y-4">
            <h3 className="font-medium">Estado e contexto</h3>
            <label className="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                checked={settings.is_active}
                onChange={(e) => updateMessageField('is_active', e.target.checked)}
              />
              Bot ativo
            </label>

            <label className="block text-sm">
              Timezone
              <input
                type="text"
                value={settings.timezone}
                onChange={(e) => updateMessageField('timezone', e.target.value)}
                className="mt-1 w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
              />
            </label>
          </section>

          <section className="border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg p-4 space-y-4">
            <h3 className="font-medium">Mensagens</h3>
            <label className="block text-sm">
              Boas-vindas
              <textarea
                value={settings.welcome_message || ''}
                onChange={(e) => updateMessageField('welcome_message', e.target.value)}
                rows={3}
                className="mt-1 w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
              />
            </label>

            <label className="block text-sm">
              Fallback (quando nao entende)
              <textarea
                value={settings.fallback_message || ''}
                onChange={(e) => updateMessageField('fallback_message', e.target.value)}
                rows={3}
                className="mt-1 w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
              />
            </label>

            <label className="block text-sm">
              Fora de horario
              <textarea
                value={settings.out_of_hours_message || ''}
                onChange={(e) => updateMessageField('out_of_hours_message', e.target.value)}
                rows={3}
                className="mt-1 w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
              />
            </label>
          </section>

          <section className="border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg p-4 space-y-4">
            <h3 className="font-medium">Horario por dia</h3>
            <div className="space-y-3">
              {DAY_KEYS.map((day) => {
                const cfg = settings.business_hours[day] || { enabled: false, start: '', end: '' };
                return (
                  <div key={day} className="grid grid-cols-1 md:grid-cols-4 gap-3 items-center border border-[#efefec] rounded p-3">
                    <label className="flex items-center gap-2 text-sm">
                      <input
                        type="checkbox"
                        checked={Boolean(cfg.enabled)}
                        onChange={(e) => updateDay(day, { enabled: e.target.checked })}
                      />
                      {DAY_LABELS[day]}
                    </label>

                    <label className="text-sm">
                      Inicio
                      <input
                        type="time"
                        value={cfg.start || ''}
                        onChange={(e) => updateDay(day, { start: e.target.value })}
                        disabled={!cfg.enabled}
                        className="mt-1 w-full rounded border border-[#d5d5d2] px-2 py-1 bg-white dark:bg-[#161615] disabled:opacity-50"
                      />
                    </label>

                    <label className="text-sm">
                      Fim
                      <input
                        type="time"
                        value={cfg.end || ''}
                        onChange={(e) => updateDay(day, { end: e.target.value })}
                        disabled={!cfg.enabled}
                        className="mt-1 w-full rounded border border-[#d5d5d2] px-2 py-1 bg-white dark:bg-[#161615] disabled:opacity-50"
                      />
                    </label>
                  </div>
                );
              })}
            </div>
          </section>

          <section className="border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg p-4 space-y-4">
            <div className="flex items-center justify-between">
              <h3 className="font-medium">Respostas por palavra-chave</h3>
              <button
                type="button"
                onClick={addKeywordReply}
                className="px-3 py-1.5 text-sm rounded border border-[#d5d5d2]"
              >
                Adicionar
              </button>
            </div>

            {!settings.keyword_replies.length && (
              <p className="text-sm text-[#706f6c]">Nenhuma regra cadastrada.</p>
            )}

            <div className="space-y-3">
              {settings.keyword_replies.map((item, index) => (
                <div key={index} className="grid grid-cols-1 md:grid-cols-5 gap-3 border border-[#efefec] rounded p-3">
                  <label className="text-sm md:col-span-1">
                    Palavra-chave
                    <input
                      type="text"
                      value={item.keyword || ''}
                      onChange={(e) => updateKeyword(index, 'keyword', e.target.value)}
                      className="mt-1 w-full rounded border border-[#d5d5d2] px-2 py-1 bg-white dark:bg-[#161615]"
                    />
                  </label>

                  <label className="text-sm md:col-span-3">
                    Resposta
                    <input
                      type="text"
                      value={item.reply || ''}
                      onChange={(e) => updateKeyword(index, 'reply', e.target.value)}
                      className="mt-1 w-full rounded border border-[#d5d5d2] px-2 py-1 bg-white dark:bg-[#161615]"
                    />
                  </label>

                  <div className="md:col-span-1 flex items-end">
                    <button
                      type="button"
                      onClick={() => removeKeywordReply(index)}
                      className="w-full px-3 py-1.5 text-sm rounded border border-red-300 text-red-700"
                    >
                      Remover
                    </button>
                  </div>
                </div>
              ))}
            </div>
          </section>

          <div className="flex items-center gap-3">
            <button
              type="submit"
              disabled={saveState === 'saving'}
              className="px-4 py-2 rounded bg-[#f53003] text-white disabled:opacity-60"
            >
              {saveState === 'saving' ? 'Salvando...' : 'Salvar configuracoes (admin)'}
            </button>

            {saveState === 'saved' && <p className="text-sm text-green-700">Configuracoes salvas com sucesso.</p>}
            {saveState === 'error' && <p className="text-sm text-red-600">{saveError}</p>}
          </div>
        </form>
      </section>

      <section className="mb-8">
        <h2 className="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A] mb-2">Uso</h2>
        <p className="text-sm">Total de conversas: <strong>{company.conversations_count ?? 0}</strong></p>
        {Array.isArray(company.conversations) && company.conversations.length > 0 && (
          <>
            <p className="text-sm text-[#706f6c] mt-2">Ultimas conversas (ate 10):</p>
            <ul className="mt-1 text-sm space-y-1">
              {company.conversations.map((conv) => (
                <li key={conv.id}>
                  {conv.customer_phone} - {conv.status} ({conv.created_at})
                </li>
              ))}
            </ul>
          </>
        )}
      </section>
    </Layout>
  );
}

function CompanyBotPage() {
  const { data, loading, error } = usePageData('/minha-conta/bot');
  const { logout } = useLogout();
  const [settings, setSettings] = useState(DEFAULT_SETTINGS);
  const [saveState, setSaveState] = useState('idle');
  const [saveError, setSaveError] = useState('');

  useEffect(() => {
    if (!data?.settings) return;
    setSettings(normalizeSettings(data.settings));
  }, [data]);

  const updateMessageField = (key, value) => {
    setSettings((prev) => ({ ...prev, [key]: value }));
  };

  const updateDay = (day, patch) => {
    setSettings((prev) => ({
      ...prev,
      business_hours: {
        ...prev.business_hours,
        [day]: {
          ...prev.business_hours[day],
          ...patch,
        },
      },
    }));
  };

  const updateKeyword = (index, key, value) => {
    setSettings((prev) => {
      const next = [...prev.keyword_replies];
      next[index] = { ...next[index], [key]: value };
      return { ...prev, keyword_replies: next };
    });
  };

  const addKeywordReply = () => {
    setSettings((prev) => ({
      ...prev,
      keyword_replies: [...prev.keyword_replies, { keyword: '', reply: '' }],
    }));
  };

  const removeKeywordReply = (index) => {
    setSettings((prev) => ({
      ...prev,
      keyword_replies: prev.keyword_replies.filter((_, i) => i !== index),
    }));
  };

  const saveSettings = async (event) => {
    event.preventDefault();
    setSaveState('saving');
    setSaveError('');

    try {
      const payload = {
        ...settings,
        keyword_replies: settings.keyword_replies.filter((item) => item.keyword?.trim() && item.reply?.trim()),
      };

      const response = await api.put('/minha-conta/bot', payload);
      setSettings(normalizeSettings(response.data?.settings));
      setSaveState('saved');
      setTimeout(() => setSaveState('idle'), 2500);
    } catch (err) {
      setSaveState('error');
      setSaveError(err.response?.data?.message || 'Falha ao salvar configuracoes.');
    }
  };

  if (loading) {
    return (
      <Layout role="company" onLogout={logout}>
        <p className="text-sm text-[#706f6c]">Carregando configuracoes do bot...</p>
      </Layout>
    );
  }

  if (error || !data?.authenticated || !data.company) {
    return (
      <Layout>
        <p className="text-sm text-red-600 dark:text-red-400">
          Nao foi possivel carregar as configuracoes do bot.
        </p>
      </Layout>
    );
  }

  const company = data.company;

  return (
    <Layout role="company" companyName={company.name} onLogout={logout}>
      <h1 className="text-xl font-medium mb-2">Configuracoes do bot - {company.name}</h1>
      <p className="text-[#706f6c] dark:text-[#A1A09A] text-sm mb-6">
        Defina mensagens, horarios e respostas por palavra-chave.
      </p>

      <form onSubmit={saveSettings} className="space-y-8 max-w-4xl">
        <section className="border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg p-4 space-y-4">
          <h2 className="font-medium">Estado e contexto</h2>
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={settings.is_active}
              onChange={(e) => updateMessageField('is_active', e.target.checked)}
            />
            Bot ativo
          </label>

          <label className="block text-sm">
            Timezone
            <input
              type="text"
              value={settings.timezone}
              onChange={(e) => updateMessageField('timezone', e.target.value)}
              className="mt-1 w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
            />
          </label>
        </section>

        <section className="border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg p-4 space-y-4">
          <h2 className="font-medium">Mensagens</h2>
          <label className="block text-sm">
            Boas-vindas
            <textarea
              value={settings.welcome_message || ''}
              onChange={(e) => updateMessageField('welcome_message', e.target.value)}
              rows={3}
              className="mt-1 w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
            />
          </label>

          <label className="block text-sm">
            Fallback (quando nao entende)
            <textarea
              value={settings.fallback_message || ''}
              onChange={(e) => updateMessageField('fallback_message', e.target.value)}
              rows={3}
              className="mt-1 w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
            />
          </label>

          <label className="block text-sm">
            Fora de horario
            <textarea
              value={settings.out_of_hours_message || ''}
              onChange={(e) => updateMessageField('out_of_hours_message', e.target.value)}
              rows={3}
              className="mt-1 w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
            />
          </label>
        </section>

        <section className="border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg p-4 space-y-4">
          <h2 className="font-medium">Horario por dia</h2>
          <div className="space-y-3">
            {DAY_KEYS.map((day) => {
              const cfg = settings.business_hours[day] || { enabled: false, start: '', end: '' };
              return (
                <div key={day} className="grid grid-cols-1 md:grid-cols-4 gap-3 items-center border border-[#efefec] rounded p-3">
                  <label className="flex items-center gap-2 text-sm">
                    <input
                      type="checkbox"
                      checked={Boolean(cfg.enabled)}
                      onChange={(e) => updateDay(day, { enabled: e.target.checked })}
                    />
                    {DAY_LABELS[day]}
                  </label>

                  <label className="text-sm">
                    Inicio
                    <input
                      type="time"
                      value={cfg.start || ''}
                      onChange={(e) => updateDay(day, { start: e.target.value })}
                      disabled={!cfg.enabled}
                      className="mt-1 w-full rounded border border-[#d5d5d2] px-2 py-1 bg-white dark:bg-[#161615] disabled:opacity-50"
                    />
                  </label>

                  <label className="text-sm">
                    Fim
                    <input
                      type="time"
                      value={cfg.end || ''}
                      onChange={(e) => updateDay(day, { end: e.target.value })}
                      disabled={!cfg.enabled}
                      className="mt-1 w-full rounded border border-[#d5d5d2] px-2 py-1 bg-white dark:bg-[#161615] disabled:opacity-50"
                    />
                  </label>
                </div>
              );
            })}
          </div>
        </section>

        <section className="border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg p-4 space-y-4">
          <div className="flex items-center justify-between">
            <h2 className="font-medium">Respostas por palavra-chave</h2>
            <button
              type="button"
              onClick={addKeywordReply}
              className="px-3 py-1.5 text-sm rounded border border-[#d5d5d2]"
            >
              Adicionar
            </button>
          </div>

          {!settings.keyword_replies.length && (
            <p className="text-sm text-[#706f6c]">Nenhuma regra cadastrada.</p>
          )}

          <div className="space-y-3">
            {settings.keyword_replies.map((item, index) => (
              <div key={index} className="grid grid-cols-1 md:grid-cols-5 gap-3 border border-[#efefec] rounded p-3">
                <label className="text-sm md:col-span-1">
                  Palavra-chave
                  <input
                    type="text"
                    value={item.keyword || ''}
                    onChange={(e) => updateKeyword(index, 'keyword', e.target.value)}
                    className="mt-1 w-full rounded border border-[#d5d5d2] px-2 py-1 bg-white dark:bg-[#161615]"
                  />
                </label>

                <label className="text-sm md:col-span-3">
                  Resposta
                  <input
                    type="text"
                    value={item.reply || ''}
                    onChange={(e) => updateKeyword(index, 'reply', e.target.value)}
                    className="mt-1 w-full rounded border border-[#d5d5d2] px-2 py-1 bg-white dark:bg-[#161615]"
                  />
                </label>

                <div className="md:col-span-1 flex items-end">
                  <button
                    type="button"
                    onClick={() => removeKeywordReply(index)}
                    className="w-full px-3 py-1.5 text-sm rounded border border-red-300 text-red-700"
                  >
                    Remover
                  </button>
                </div>
              </div>
            ))}
          </div>
        </section>

        <div className="flex items-center gap-3">
          <button
            type="submit"
            disabled={saveState === 'saving'}
            className="px-4 py-2 rounded bg-[#f53003] text-white disabled:opacity-60"
          >
            {saveState === 'saving' ? 'Salvando...' : 'Salvar configuracoes'}
          </button>

          {saveState === 'saved' && <p className="text-sm text-green-700">Configuracoes salvas com sucesso.</p>}
          {saveState === 'error' && <p className="text-sm text-red-600">{saveError}</p>}
        </div>
      </form>
    </Layout>
  );
}

function CompanySimulatorPage() {
  const { data, loading, error } = usePageData('/minha-conta/bot');
  const { logout } = useLogout();
  const [from, setFrom] = useState('5511999999999');
  const [text, setText] = useState('');
  const [sendOutbound, setSendOutbound] = useState(true);
  const [result, setResult] = useState(null);
  const [actionError, setActionError] = useState('');
  const [busy, setBusy] = useState(false);

  const runSimulation = async (event) => {
    event.preventDefault();
    if (!data?.company?.id) return;

    setBusy(true);
    setActionError('');
    setResult(null);

    try {
      const response = await api.post('/simular/mensagem', {
        company_id: data.company.id,
        from,
        text,
        send_outbound: sendOutbound,
      });
      setResult(response.data);
    } catch (err) {
      setActionError(err.response?.data?.message || 'Falha ao simular mensagem.');
    } finally {
      setBusy(false);
    }
  };

  if (loading) {
    return (
      <Layout role="company" onLogout={logout}>
        <p className="text-sm text-[#706f6c]">Carregando simulador...</p>
      </Layout>
    );
  }

  if (error || !data?.authenticated || !data?.company) {
    return (
      <Layout>
        <p className="text-sm text-red-600 dark:text-red-400">Nao foi possivel carregar o simulador.</p>
      </Layout>
    );
  }

  return (
    <Layout role="company" companyName={data.company.name} onLogout={logout}>
      <h1 className="text-xl font-medium mb-2">Simulador - {data.company.name}</h1>
      <p className="text-[#706f6c] dark:text-[#A1A09A] text-sm mb-6">
        Teste respostas do bot sem depender da Meta.
      </p>

      <form onSubmit={runSimulation} className="space-y-4 max-w-2xl">
        <label className="block text-sm">
          Telefone do cliente
          <input
            type="text"
            value={from}
            onChange={(e) => setFrom(e.target.value)}
            className="mt-1 w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
          />
        </label>

        <label className="block text-sm">
          Mensagem recebida
          <textarea
            value={text}
            onChange={(e) => setText(e.target.value)}
            rows={4}
            className="mt-1 w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
          />
        </label>

        <label className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            checked={sendOutbound}
            onChange={(e) => setSendOutbound(e.target.checked)}
          />
          Tentar envio externo (se tiver credenciais)
        </label>

        <button
          type="submit"
          disabled={busy}
          className="px-4 py-2 rounded bg-[#f53003] text-white disabled:opacity-60"
        >
          {busy ? 'Simulando...' : 'Simular mensagem'}
        </button>
      </form>

      {actionError && <p className="text-sm text-red-600 mt-4">{actionError}</p>}

      {result && (
        <section className="mt-6 border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg p-4">
          <h2 className="font-medium mb-2">Resultado</h2>
          <ul className="text-sm space-y-1">
            <li>Conversa ID: {result.conversation?.id}</li>
            <li>Inbound ID: {result.in_message?.id}</li>
            <li>Outbound ID: {result.out_message?.id ?? '-'}</li>
            <li>Resposta do bot: {result.reply ?? '(sem resposta automatica: conversa em modo manual)'}</li>
            <li>Envio externo: {result.was_sent ? 'Sim' : 'Nao'}</li>
          </ul>
        </section>
      )}
    </Layout>
  );
}

function AdminSimulatorPage() {
  const { data, loading, error } = usePageData('/admin/empresas');
  const { logout } = useLogout();
  const [companyId, setCompanyId] = useState('');
  const [from, setFrom] = useState('5511999999999');
  const [text, setText] = useState('');
  const [sendOutbound, setSendOutbound] = useState(true);
  const [result, setResult] = useState(null);
  const [actionError, setActionError] = useState('');
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    const firstCompanyId = data?.companies?.[0]?.id;
    if (!firstCompanyId) return;
    if (!companyId) {
      setCompanyId(String(firstCompanyId));
    }
  }, [data, companyId]);

  const runSimulation = async (event) => {
    event.preventDefault();
    if (!companyId) return;

    setBusy(true);
    setActionError('');
    setResult(null);

    try {
      const response = await api.post('/simular/mensagem', {
        company_id: Number(companyId),
        from,
        text,
        send_outbound: sendOutbound,
      });
      setResult(response.data);
    } catch (err) {
      setActionError(err.response?.data?.message || 'Falha ao simular mensagem.');
    } finally {
      setBusy(false);
    }
  };

  if (loading) {
    return (
      <Layout role="admin" onLogout={logout}>
        <p className="text-sm text-[#706f6c]">Carregando simulador...</p>
      </Layout>
    );
  }

  if (error || !data?.authenticated) {
    return (
      <Layout>
        <p className="text-sm text-red-600 dark:text-red-400">Nao foi possivel carregar o simulador.</p>
      </Layout>
    );
  }

  return (
    <Layout role="admin" onLogout={logout}>
      <h1 className="text-xl font-medium mb-2">Simulador (admin)</h1>
      <p className="text-[#706f6c] dark:text-[#A1A09A] text-sm mb-6">
        Execute testes de mensagem para qualquer empresa.
      </p>

      <form onSubmit={runSimulation} className="space-y-4 max-w-2xl">
        <label className="block text-sm">
          Empresa
          <select
            value={companyId}
            onChange={(e) => setCompanyId(e.target.value)}
            className="mt-1 w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
          >
            {(data.companies ?? []).map((company) => (
              <option key={company.id} value={company.id}>
                {company.name}
              </option>
            ))}
          </select>
        </label>

        <label className="block text-sm">
          Telefone do cliente
          <input
            type="text"
            value={from}
            onChange={(e) => setFrom(e.target.value)}
            className="mt-1 w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
          />
        </label>

        <label className="block text-sm">
          Mensagem recebida
          <textarea
            value={text}
            onChange={(e) => setText(e.target.value)}
            rows={4}
            className="mt-1 w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
          />
        </label>

        <label className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            checked={sendOutbound}
            onChange={(e) => setSendOutbound(e.target.checked)}
          />
          Tentar envio externo (se tiver credenciais)
        </label>

        <button
          type="submit"
          disabled={busy}
          className="px-4 py-2 rounded bg-[#f53003] text-white disabled:opacity-60"
        >
          {busy ? 'Simulando...' : 'Simular mensagem'}
        </button>
      </form>

      {actionError && <p className="text-sm text-red-600 mt-4">{actionError}</p>}

      {result && (
        <section className="mt-6 border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg p-4">
          <h2 className="font-medium mb-2">Resultado</h2>
          <ul className="text-sm space-y-1">
            <li>Empresa ID: {result.company_id}</li>
            <li>Conversa ID: {result.conversation?.id}</li>
            <li>Resposta do bot: {result.reply ?? '(sem resposta automatica: conversa em modo manual)'}</li>
            <li>Envio externo: {result.was_sent ? 'Sim' : 'Nao'}</li>
          </ul>
        </section>
      )}
    </Layout>
  );
}

function CompanyInboxPage() {
  const { data, loading, error } = usePageData('/minha-conta/conversas');
  const { logout } = useLogout();
  const [conversations, setConversations] = useState([]);
  const [selectedId, setSelectedId] = useState(null);
  const [detail, setDetail] = useState(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [detailError, setDetailError] = useState('');
  const [manualText, setManualText] = useState('');
  const [manualBusy, setManualBusy] = useState(false);
  const [manualError, setManualError] = useState('');
  const [actionBusy, setActionBusy] = useState(false);

  useEffect(() => {
    setConversations(data?.conversations ?? []);
  }, [data]);

  const refreshConversations = async () => {
    const response = await api.get('/minha-conta/conversas');
    setConversations(response.data?.conversations ?? []);
  };

  const openConversation = async (conversationId) => {
    setSelectedId(conversationId);
    setDetailLoading(true);
    setDetailError('');
    setDetail(null);
    try {
      const response = await api.get(`/minha-conta/conversas/${conversationId}`);
      setDetail(response.data?.conversation ?? null);
    } catch (err) {
      setDetailError(err.response?.data?.message || 'Falha ao carregar conversa.');
    } finally {
      setDetailLoading(false);
    }
  };

  const assumeConversation = async () => {
    if (!detail?.id) return;
    setActionBusy(true);
    try {
      const response = await api.post(`/minha-conta/conversas/${detail.id}/assumir`);
      setDetail((prev) => ({ ...(prev ?? {}), ...response.data?.conversation }));
      await refreshConversations();
    } catch (err) {
      setDetailError(err.response?.data?.message || 'Falha ao assumir conversa.');
    } finally {
      setActionBusy(false);
    }
  };

  const releaseConversation = async () => {
    if (!detail?.id) return;
    setActionBusy(true);
    try {
      const response = await api.post(`/minha-conta/conversas/${detail.id}/soltar`);
      setDetail((prev) => ({ ...(prev ?? {}), ...response.data?.conversation }));
      await refreshConversations();
    } catch (err) {
      setDetailError(err.response?.data?.message || 'Falha ao soltar conversa.');
    } finally {
      setActionBusy(false);
    }
  };

  const sendManualReply = async (event) => {
    event.preventDefault();
    if (!detail?.id || !manualText.trim()) return;

    setManualBusy(true);
    setManualError('');
    try {
      const response = await api.post(`/minha-conta/conversas/${detail.id}/responder-manual`, {
        text: manualText.trim(),
        send_outbound: true,
      });
      const message = response.data?.message;
      setDetail((prev) => ({
        ...(prev ?? {}),
        ...response.data?.conversation,
        messages: [...(prev?.messages ?? []), message],
      }));
      setManualText('');
      await refreshConversations();
    } catch (err) {
      setManualError(err.response?.data?.message || 'Falha ao enviar resposta manual.');
    } finally {
      setManualBusy(false);
    }
  };

  if (loading) {
    return (
      <Layout role="company" onLogout={logout}>
        <p className="text-sm text-[#706f6c]">Carregando inbox...</p>
      </Layout>
    );
  }

  if (error || !data?.authenticated) {
    return (
      <Layout>
        <p className="text-sm text-red-600 dark:text-red-400">Nao foi possivel carregar a inbox.</p>
      </Layout>
    );
  }

  return (
    <Layout role="company" onLogout={logout}>
      <h1 className="text-xl font-medium mb-4">Inbox da empresa</h1>
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        <section className="border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg p-4">
          <h2 className="font-medium mb-3">Conversas</h2>
          {!conversations.length && <p className="text-sm text-[#706f6c]">Nenhuma conversa.</p>}
          <ul className="space-y-2 text-sm">
            {conversations.map((conv) => (
              <li key={conv.id}>
                <button
                  type="button"
                  onClick={() => openConversation(conv.id)}
                  className={`w-full text-left px-3 py-2 rounded border ${
                    selectedId === conv.id ? 'border-[#f53003]' : 'border-[#e3e3e0]'
                  }`}
                >
                  {conv.customer_phone} - {conv.status} ({conv.messages_count ?? 0} msg)
                  {conv.handling_mode === 'manual' ? ' | manual' : ' | bot'}
                </button>
              </li>
            ))}
          </ul>
        </section>

        <section className="border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg p-4">
          <h2 className="font-medium mb-3">Mensagens</h2>
          {detailLoading && <p className="text-sm text-[#706f6c]">Carregando conversa...</p>}
          {detailError && <p className="text-sm text-red-600">{detailError}</p>}
          {!detailLoading && !detail && !detailError && (
            <p className="text-sm text-[#706f6c]">Selecione uma conversa.</p>
          )}
          {!!detail && (
            <>
              <div className="mb-3 text-xs text-[#706f6c]">
                Modo: <strong>{detail.handling_mode === 'manual' ? 'Manual' : 'Bot'}</strong>{' '}
                {detail.assigned_user ? `| Assumida por: ${detail.assigned_user.name}` : ''}
              </div>

              <div className="flex gap-2 mb-3">
                <button
                  type="button"
                  onClick={assumeConversation}
                  disabled={actionBusy}
                  className="px-3 py-1 text-sm rounded border border-[#d5d5d2]"
                >
                  Assumir
                </button>
                <button
                  type="button"
                  onClick={releaseConversation}
                  disabled={actionBusy}
                  className="px-3 py-1 text-sm rounded border border-[#d5d5d2]"
                >
                  Soltar para bot
                </button>
              </div>

              <ul className="space-y-2 text-sm mb-3 max-h-80 overflow-y-auto pr-1">
                {(detail.messages ?? []).map((msg) => (
                  <li key={msg.id} className="border border-[#e3e3e0] rounded p-2">
                    <strong>{msg.direction === 'in' ? 'Cliente' : 'Atendente/Bot'}:</strong> {msg.text}
                  </li>
                ))}
              </ul>

              <form onSubmit={sendManualReply} className="space-y-2">
                <textarea
                  value={manualText}
                  onChange={(e) => setManualText(e.target.value)}
                  rows={3}
                  placeholder="Digite resposta manual..."
                  className="w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615] text-sm"
                />
                <button
                  type="submit"
                  disabled={manualBusy}
                  className="px-3 py-1.5 text-sm rounded bg-[#f53003] text-white disabled:opacity-60"
                >
                  {manualBusy ? 'Enviando...' : 'Enviar resposta manual'}
                </button>
                {manualError && <p className="text-sm text-red-600">{manualError}</p>}
              </form>
            </>
          )}
        </section>
      </div>
    </Layout>
  );
}

function AdminInboxPage() {
  const { data, loading, error } = usePageData('/admin/empresas');
  const { logout } = useLogout();
  const [companyId, setCompanyId] = useState('');
  const [conversations, setConversations] = useState([]);
  const [listLoading, setListLoading] = useState(false);
  const [selectedId, setSelectedId] = useState(null);
  const [detail, setDetail] = useState(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [detailError, setDetailError] = useState('');
  const [manualText, setManualText] = useState('');
  const [manualBusy, setManualBusy] = useState(false);
  const [manualError, setManualError] = useState('');
  const [actionBusy, setActionBusy] = useState(false);

  useEffect(() => {
    const firstCompanyId = data?.companies?.[0]?.id;
    if (!firstCompanyId || companyId) return;
    setCompanyId(String(firstCompanyId));
  }, [data, companyId]);

  useEffect(() => {
    if (!companyId) return;
    let canceled = false;
    setListLoading(true);
    api
      .get(`/admin/conversas?company_id=${companyId}`)
      .then((response) => {
        if (canceled) return;
        setConversations(response.data?.conversations ?? []);
      })
      .finally(() => {
        if (!canceled) setListLoading(false);
      });

    return () => {
      canceled = true;
    };
  }, [companyId]);

  const openConversation = async (conversationId) => {
    setSelectedId(conversationId);
    setDetailLoading(true);
    setDetailError('');
    setDetail(null);
    try {
      const response = await api.get(`/admin/conversas/${conversationId}`);
      setDetail(response.data?.conversation ?? null);
    } catch (err) {
      setDetailError(err.response?.data?.message || 'Falha ao carregar conversa.');
    } finally {
      setDetailLoading(false);
    }
  };

  const refreshConversations = async (forcedCompanyId = null) => {
    const targetCompanyId = forcedCompanyId ?? companyId;
    if (!targetCompanyId) return;
    const response = await api.get(`/admin/conversas?company_id=${targetCompanyId}`);
    setConversations(response.data?.conversations ?? []);
  };

  const assumeConversation = async () => {
    if (!detail?.id) return;
    setActionBusy(true);
    try {
      const response = await api.post(`/admin/conversas/${detail.id}/assumir`);
      setDetail((prev) => ({ ...(prev ?? {}), ...response.data?.conversation }));
      await refreshConversations();
    } catch (err) {
      setDetailError(err.response?.data?.message || 'Falha ao assumir conversa.');
    } finally {
      setActionBusy(false);
    }
  };

  const releaseConversation = async () => {
    if (!detail?.id) return;
    setActionBusy(true);
    try {
      const response = await api.post(`/admin/conversas/${detail.id}/soltar`);
      setDetail((prev) => ({ ...(prev ?? {}), ...response.data?.conversation }));
      await refreshConversations();
    } catch (err) {
      setDetailError(err.response?.data?.message || 'Falha ao soltar conversa.');
    } finally {
      setActionBusy(false);
    }
  };

  const sendManualReply = async (event) => {
    event.preventDefault();
    if (!detail?.id || !manualText.trim()) return;

    setManualBusy(true);
    setManualError('');
    try {
      const response = await api.post(`/admin/conversas/${detail.id}/responder-manual`, {
        text: manualText.trim(),
        send_outbound: true,
      });
      const message = response.data?.message;
      setDetail((prev) => ({
        ...(prev ?? {}),
        ...response.data?.conversation,
        messages: [...(prev?.messages ?? []), message],
      }));
      setManualText('');
      await refreshConversations();
    } catch (err) {
      setManualError(err.response?.data?.message || 'Falha ao enviar resposta manual.');
    } finally {
      setManualBusy(false);
    }
  };

  if (loading) {
    return (
      <Layout role="admin" onLogout={logout}>
        <p className="text-sm text-[#706f6c]">Carregando inbox...</p>
      </Layout>
    );
  }

  if (error || !data?.authenticated) {
    return (
      <Layout>
        <p className="text-sm text-red-600 dark:text-red-400">Nao foi possivel carregar a inbox.</p>
      </Layout>
    );
  }

  return (
    <Layout role="admin" onLogout={logout}>
      <h1 className="text-xl font-medium mb-4">Inbox (admin)</h1>
      <div className="mb-4 max-w-sm">
        <label className="block text-sm">
          Empresa
          <select
            value={companyId}
            onChange={(e) => setCompanyId(e.target.value)}
            className="mt-1 w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
          >
            {(data.companies ?? []).map((company) => (
              <option key={company.id} value={company.id}>
                {company.name}
              </option>
            ))}
          </select>
        </label>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        <section className="border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg p-4">
          <h2 className="font-medium mb-3">Conversas</h2>
          {listLoading && <p className="text-sm text-[#706f6c]">Carregando conversas...</p>}
          {!listLoading && !conversations.length && <p className="text-sm text-[#706f6c]">Nenhuma conversa.</p>}
          <ul className="space-y-2 text-sm">
            {conversations.map((conv) => (
              <li key={conv.id}>
                <button
                  type="button"
                  onClick={() => openConversation(conv.id)}
                  className={`w-full text-left px-3 py-2 rounded border ${
                    selectedId === conv.id ? 'border-[#f53003]' : 'border-[#e3e3e0]'
                  }`}
                >
                  {conv.customer_phone} - {conv.status} ({conv.messages_count ?? 0} msg)
                  {conv.handling_mode === 'manual' ? ' | manual' : ' | bot'}
                </button>
              </li>
            ))}
          </ul>
        </section>

        <section className="border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg p-4">
          <h2 className="font-medium mb-3">Mensagens</h2>
          {detailLoading && <p className="text-sm text-[#706f6c]">Carregando conversa...</p>}
          {detailError && <p className="text-sm text-red-600">{detailError}</p>}
          {!detailLoading && !detail && !detailError && (
            <p className="text-sm text-[#706f6c]">Selecione uma conversa.</p>
          )}
          {!!detail && (
            <>
              <div className="mb-3 text-xs text-[#706f6c]">
                Modo: <strong>{detail.handling_mode === 'manual' ? 'Manual' : 'Bot'}</strong>{' '}
                {detail.assigned_user ? `| Assumida por: ${detail.assigned_user.name}` : ''}
              </div>

              <div className="flex gap-2 mb-3">
                <button
                  type="button"
                  onClick={assumeConversation}
                  disabled={actionBusy}
                  className="px-3 py-1 text-sm rounded border border-[#d5d5d2]"
                >
                  Assumir
                </button>
                <button
                  type="button"
                  onClick={releaseConversation}
                  disabled={actionBusy}
                  className="px-3 py-1 text-sm rounded border border-[#d5d5d2]"
                >
                  Soltar para bot
                </button>
              </div>

              <ul className="space-y-2 text-sm mb-3 max-h-80 overflow-y-auto pr-1">
                {(detail.messages ?? []).map((msg) => (
                  <li key={msg.id} className="border border-[#e3e3e0] rounded p-2">
                    <strong>{msg.direction === 'in' ? 'Cliente' : 'Atendente/Bot'}:</strong> {msg.text}
                  </li>
                ))}
              </ul>

              <form onSubmit={sendManualReply} className="space-y-2">
                <textarea
                  value={manualText}
                  onChange={(e) => setManualText(e.target.value)}
                  rows={3}
                  placeholder="Digite resposta manual..."
                  className="w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615] text-sm"
                />
                <button
                  type="submit"
                  disabled={manualBusy}
                  className="px-3 py-1.5 text-sm rounded bg-[#f53003] text-white disabled:opacity-60"
                >
                  {manualBusy ? 'Enviando...' : 'Enviar resposta manual'}
                </button>
                {manualError && <p className="text-sm text-red-600">{manualError}</p>}
              </form>
            </>
          )}
        </section>
      </div>
    </Layout>
  );
}

function AdminUsersPage() {
  const { data, loading, error } = usePageData('/admin/users');
  const { data: companiesData } = usePageData('/admin/empresas');
  const { logout } = useLogout();
  const [createBusy, setCreateBusy] = useState(false);
  const [createError, setCreateError] = useState('');
  const [editBusy, setEditBusy] = useState(false);
  const [editError, setEditError] = useState('');
  const [selectedUserId, setSelectedUserId] = useState(null);
  const [createForm, setCreateForm] = useState({
    name: '',
    email: '',
    password: '',
    role: 'company',
    company_id: '',
    is_active: true,
  });
  const [editForm, setEditForm] = useState(null);

  const users = data?.users ?? [];
  const companies = companiesData?.companies ?? [];

  const handleCreate = async (event) => {
    event.preventDefault();
    setCreateBusy(true);
    setCreateError('');
    try {
      const payload = {
        ...createForm,
        company_id: createForm.role === 'company' ? Number(createForm.company_id) : null,
      };
      await api.post('/admin/users', payload);
      window.location.reload();
    } catch (err) {
      setCreateError(err.response?.data?.message || 'Falha ao criar usuario.');
    } finally {
      setCreateBusy(false);
    }
  };

  const beginEdit = (user) => {
    setSelectedUserId(user.id);
    setEditForm({
      id: user.id,
      name: user.name,
      email: user.email,
      password: '',
      role: user.role,
      company_id: user.company_id ? String(user.company_id) : '',
      is_active: Boolean(user.is_active),
    });
  };

  const handleEdit = async (event) => {
    event.preventDefault();
    if (!editForm?.id) return;

    setEditBusy(true);
    setEditError('');
    try {
      const payload = {
        ...editForm,
        company_id: editForm.role === 'company' ? Number(editForm.company_id) : null,
      };
      if (!payload.password) {
        delete payload.password;
      }
      await api.put(`/admin/users/${editForm.id}`, payload);
      window.location.reload();
    } catch (err) {
      setEditError(err.response?.data?.message || 'Falha ao atualizar usuario.');
    } finally {
      setEditBusy(false);
    }
  };

  if (loading) {
    return (
      <Layout role="admin" onLogout={logout}>
        <p className="text-sm text-[#706f6c]">Carregando usuarios...</p>
      </Layout>
    );
  }

  if (error || !data?.authenticated) {
    return (
      <Layout>
        <p className="text-sm text-red-600 dark:text-red-400">Nao foi possivel carregar usuarios.</p>
      </Layout>
    );
  }

  return (
    <Layout role="admin" onLogout={logout}>
      <h1 className="text-xl font-medium mb-4">Usuarios</h1>
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <section className="border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg p-4">
          <h2 className="font-medium mb-3">Criar usuario</h2>
          <form onSubmit={handleCreate} className="space-y-3">
            <input
              type="text"
              placeholder="Nome"
              value={createForm.name}
              onChange={(e) => setCreateForm((p) => ({ ...p, name: e.target.value }))}
              required
              className="w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
            />
            <input
              type="email"
              placeholder="Email"
              value={createForm.email}
              onChange={(e) => setCreateForm((p) => ({ ...p, email: e.target.value }))}
              required
              className="w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
            />
            <input
              type="password"
              placeholder="Senha (min 8)"
              value={createForm.password}
              onChange={(e) => setCreateForm((p) => ({ ...p, password: e.target.value }))}
              required
              className="w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
            />
            <select
              value={createForm.role}
              onChange={(e) => setCreateForm((p) => ({ ...p, role: e.target.value, company_id: '' }))}
              className="w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
            >
              <option value="company">company</option>
              <option value="admin">admin</option>
            </select>
            {createForm.role === 'company' && (
              <select
                value={createForm.company_id}
                onChange={(e) => setCreateForm((p) => ({ ...p, company_id: e.target.value }))}
                required
                className="w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
              >
                <option value="">Selecione empresa</option>
                {companies.map((company) => (
                  <option key={company.id} value={company.id}>{company.name}</option>
                ))}
              </select>
            )}
            <label className="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                checked={createForm.is_active}
                onChange={(e) => setCreateForm((p) => ({ ...p, is_active: e.target.checked }))}
              />
              Usuario ativo
            </label>
            <button
              type="submit"
              disabled={createBusy}
              className="px-4 py-2 rounded bg-[#f53003] text-white disabled:opacity-60"
            >
              {createBusy ? 'Criando...' : 'Criar usuario'}
            </button>
            {createError && <p className="text-sm text-red-600">{createError}</p>}
          </form>
        </section>

        <section className="border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-lg p-4">
          <h2 className="font-medium mb-3">Usuarios cadastrados</h2>
          {!users.length && <p className="text-sm text-[#706f6c]">Nenhum usuario.</p>}
          <ul className="space-y-2 text-sm mb-4 max-h-64 overflow-y-auto pr-1">
            {users.map((user) => (
              <li key={user.id} className="border border-[#e3e3e0] rounded p-2">
                <div className="flex items-center justify-between gap-2">
                  <div>
                    <strong>{user.name}</strong> ({user.role}){user.is_active ? '' : ' [inativo]'}
                    <div className="text-xs text-[#706f6c]">{user.email} {user.company?.name ? `- ${user.company.name}` : ''}</div>
                  </div>
                  <button
                    type="button"
                    onClick={() => beginEdit(user)}
                    className="px-2 py-1 rounded border border-[#d5d5d2]"
                  >
                    Editar
                  </button>
                </div>
              </li>
            ))}
          </ul>

          {editForm && (
            <form onSubmit={handleEdit} className="space-y-2 border-t pt-3">
              <h3 className="font-medium text-sm">Editar usuario #{selectedUserId}</h3>
              <input
                type="text"
                value={editForm.name}
                onChange={(e) => setEditForm((p) => ({ ...p, name: e.target.value }))}
                className="w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
              />
              <input
                type="email"
                value={editForm.email}
                onChange={(e) => setEditForm((p) => ({ ...p, email: e.target.value }))}
                className="w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
              />
              <input
                type="password"
                placeholder="Nova senha (opcional)"
                value={editForm.password}
                onChange={(e) => setEditForm((p) => ({ ...p, password: e.target.value }))}
                className="w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
              />
              <select
                value={editForm.role}
                onChange={(e) => setEditForm((p) => ({ ...p, role: e.target.value, company_id: '' }))}
                className="w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
              >
                <option value="company">company</option>
                <option value="admin">admin</option>
              </select>
              {editForm.role === 'company' && (
                <select
                  value={editForm.company_id}
                  onChange={(e) => setEditForm((p) => ({ ...p, company_id: e.target.value }))}
                  required
                  className="w-full rounded border border-[#d5d5d2] px-3 py-2 bg-white dark:bg-[#161615]"
                >
                  <option value="">Selecione empresa</option>
                  {companies.map((company) => (
                    <option key={company.id} value={company.id}>{company.name}</option>
                  ))}
                </select>
              )}
              <label className="flex items-center gap-2 text-sm">
                <input
                  type="checkbox"
                  checked={editForm.is_active}
                  onChange={(e) => setEditForm((p) => ({ ...p, is_active: e.target.checked }))}
                />
                Usuario ativo
              </label>
              <button
                type="submit"
                disabled={editBusy}
                className="px-4 py-2 rounded bg-[#f53003] text-white disabled:opacity-60"
              >
                {editBusy ? 'Salvando...' : 'Salvar usuario'}
              </button>
              {editError && <p className="text-sm text-red-600">{editError}</p>}
            </form>
          )}
        </section>
      </div>
    </Layout>
  );
}

function NotFoundPage() {
  return (
    <Layout>
      <h1 className="text-xl font-medium mb-2">Pagina nao encontrada</h1>
      <p className="text-sm text-[#706f6c] dark:text-[#A1A09A] mb-4">
        Verifique a URL ou volte para a tela de entrada.
      </p>
      <a href="/entrar" className="inline-block px-4 py-2 rounded border border-[#e3e3e0] dark:border-[#3E3E3A]">
        Voltar para Entrar
      </a>
    </Layout>
  );
}

function AppRouter() {
  const path = window.location.pathname;
  const segments = path.replace(/^\/+|\/+$/g, '').split('/');

  if (path === '/' || path === '/entrar') {
    return <EntrarPage />;
  }

  if (path === '/dashboard') {
    return <DashboardPage />;
  }

  if (path === '/admin/empresas') {
    return <AdminCompaniesPage />;
  }

  if (path === '/admin/simulador') {
    return <AdminSimulatorPage />;
  }

  if (path === '/admin/conversas') {
    return <AdminInboxPage />;
  }

  if (path === '/admin/usuarios') {
    return <AdminUsersPage />;
  }

  if (segments[0] === 'admin' && segments[1] === 'empresas' && segments[2]) {
    return <AdminCompanyShowPage companyId={segments[2]} />;
  }

  if (path === '/minha-conta/simulador') {
    return <CompanySimulatorPage />;
  }

  if (path === '/minha-conta/conversas') {
    return <CompanyInboxPage />;
  }

  if (path === '/minha-conta/bot') {
    return <CompanyBotPage />;
  }

  return <NotFoundPage />;
}

const rootElement = document.getElementById('root');

if (rootElement) {
  ReactDOM.createRoot(rootElement).render(
    <React.StrictMode>
      <AppRouter />
    </React.StrictMode>
  );
}
