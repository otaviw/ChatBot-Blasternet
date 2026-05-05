import { useEffect, useMemo, useState } from 'react';
import Layout from '@/components/layout/Layout/Layout.jsx';
import PageLoading from '@/components/ui/PageLoading/PageLoading.jsx';
import useLogout from '@/hooks/useLogout';
import usePageData from '@/hooks/usePageData';
import api from '@/services/api';

function CompanyMyCompanyPage() {
  const { data, loading, error, setData } = usePageData('/minha-conta/empresa');
  const { logout } = useLogout();
  const [saving, setSaving] = useState(false);
  const [status, setStatus] = useState({ type: 'idle', message: '' });
  const [testState, setTestState] = useState({ type: 'idle', message: '' });
  const [form, setForm] = useState({
    ixc_base_url: '',
    ixc_api_token: '',
    ixc_self_signed: false,
    ixc_timeout_seconds: 15,
    ixc_enabled: false,
  });

  useEffect(() => {
    const company = data?.company;
    if (!company) return;

    setForm({
      ixc_base_url: String(company.ixc_base_url ?? ''),
      ixc_api_token: '',
      ixc_self_signed: Boolean(company.ixc_self_signed),
      ixc_timeout_seconds: Number(company.ixc_timeout_seconds ?? 15),
      ixc_enabled: Boolean(company.ixc_enabled),
    });
  }, [data?.company]);

  const canSubmit = useMemo(() => !saving, [saving]);

  const save = async (event) => {
    event.preventDefault();
    setSaving(true);
    setStatus({ type: 'idle', message: '' });
    try {
      const payload = {
        ixc_base_url: String(form.ixc_base_url ?? '').trim() || null,
        ixc_self_signed: Boolean(form.ixc_self_signed),
        ixc_timeout_seconds: Math.max(5, Math.min(60, Number(form.ixc_timeout_seconds || 15))),
        ixc_enabled: Boolean(form.ixc_enabled),
      };

      if (String(form.ixc_api_token ?? '').trim() !== '') {
        payload.ixc_api_token = String(form.ixc_api_token).trim();
      }

      const response = await api.put('/minha-conta/empresa', payload);
      const company = response?.data?.company;
      if (company) {
        setData((previous) => ({ ...(previous ?? {}), ok: true, company }));
      }
      setForm((previous) => ({ ...previous, ixc_api_token: '' }));
      setStatus({ type: 'ok', message: 'Configuracao IXC salva com sucesso.' });
    } catch (err) {
      setStatus({
        type: 'error',
        message: err?.response?.data?.message || 'Falha ao salvar configuracao IXC.',
      });
    } finally {
      setSaving(false);
    }
  };

  const testConnection = async () => {
    setTestState({ type: 'loading', message: '' });
    try {
      const payload = {
        base_url: String(form.ixc_base_url ?? '').trim() || undefined,
        self_signed: Boolean(form.ixc_self_signed),
        timeout_seconds: Math.max(5, Math.min(60, Number(form.ixc_timeout_seconds || 15))),
      };
      if (String(form.ixc_api_token ?? '').trim() !== '') {
        payload.api_token = String(form.ixc_api_token).trim();
      }

      const response = await api.post('/minha-conta/empresa/validar-ixc', payload);
      if (response?.data?.ok) {
        setTestState({ type: 'ok', message: 'Conexao IXC validada com sucesso.' });
      } else {
        setTestState({ type: 'error', message: response?.data?.error || 'Falha na validacao IXC.' });
      }
    } catch (err) {
      setTestState({
        type: 'error',
        message: err?.response?.data?.error || err?.response?.data?.message || 'Falha ao validar IXC.',
      });
    }
  };

  if (loading) {
    return (
      <Layout role="company" onLogout={logout}>
        <PageLoading rows={2} cards={1} />
      </Layout>
    );
  }

  if (error || !data?.company) {
    return (
      <Layout role="company" onLogout={logout}>
        <p className="text-sm text-red-600">Nao foi possivel carregar os dados da empresa.</p>
      </Layout>
    );
  }

  return (
    <Layout role="company" onLogout={logout}>
      <h1 className="app-page-title">Minha empresa</h1>
      <p className="app-page-subtitle mb-6">Configuracao da integracao IXC por tenant.</p>

      <section className="app-panel">
        <form onSubmit={save} className="grid grid-cols-1 md:grid-cols-2 gap-3">
          <label className="block text-sm md:col-span-2">
            URL base IXC
            <input
              type="url"
              value={form.ixc_base_url}
              onChange={(event) => setForm((previous) => ({ ...previous, ixc_base_url: event.target.value }))}
              className="app-input"
              placeholder="https://ip-ou-dominio/webservice/v1"
            />
          </label>

          <label className="block text-sm md:col-span-2">
            Token IXC
            <input
              type="password"
              value={form.ixc_api_token}
              onChange={(event) => setForm((previous) => ({ ...previous, ixc_api_token: event.target.value }))}
              className="app-input"
              placeholder="Informe apenas para atualizar"
            />
          </label>

          <label className="block text-sm">
            Timeout (segundos)
            <input
              type="number"
              min={5}
              max={60}
              value={form.ixc_timeout_seconds}
              onChange={(event) => setForm((previous) => ({ ...previous, ixc_timeout_seconds: Number(event.target.value) }))}
              className="app-input"
            />
          </label>

          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={Boolean(form.ixc_self_signed)}
              onChange={(event) => setForm((previous) => ({ ...previous, ixc_self_signed: event.target.checked }))}
            />
            Permitir certificado autoassinado
          </label>

          <label className="flex items-center gap-2 text-sm md:col-span-2">
            <input
              type="checkbox"
              checked={Boolean(form.ixc_enabled)}
              onChange={(event) => setForm((previous) => ({ ...previous, ixc_enabled: event.target.checked }))}
            />
            Habilitar modulo IXC para esta empresa
          </label>

          <div className="md:col-span-2 flex flex-wrap gap-2">
            <button type="button" onClick={testConnection} className="app-btn-secondary" disabled={testState.type === 'loading'}>
              {testState.type === 'loading' ? 'Testando...' : 'Testar conexao IXC'}
            </button>
            <button type="submit" className="app-btn-primary" disabled={!canSubmit}>
              {saving ? 'Salvando...' : 'Salvar configuracao'}
            </button>
          </div>
        </form>

        {status.type === 'ok' ? <p className="text-sm text-green-700 mt-3">{status.message}</p> : null}
        {status.type === 'error' ? <p className="text-sm text-red-600 mt-3">{status.message}</p> : null}
        {testState.type === 'ok' ? <p className="text-sm text-green-700 mt-1">{testState.message}</p> : null}
        {testState.type === 'error' ? <p className="text-sm text-red-600 mt-1">{testState.message}</p> : null}
      </section>
    </Layout>
  );
}

export default CompanyMyCompanyPage;
