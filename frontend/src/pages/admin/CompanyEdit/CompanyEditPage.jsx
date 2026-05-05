import { useEffect, useMemo, useState } from 'react';
import { Navigate, useLocation, useNavigate, useParams } from 'react-router-dom';
import Layout from '@/components/layout/Layout/Layout.jsx';
import PageLoading from '@/components/ui/PageLoading/PageLoading.jsx';
import useAuth from '@/hooks/useAuth';
import useLogout from '@/hooks/useLogout';
import api from '@/services/api';
import { getScopedAuthPaths } from '@/utils/tenantRouting';

function CompanyEditPage() {
  const { id = '' } = useParams();
  const location = useLocation();
  const navigate = useNavigate();
  const { user, loading: authLoading } = useAuth();
  const { logout } = useLogout();
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');
  const [form, setForm] = useState({
    name: '',
    meta_phone_number_id: '',
    meta_waba_id: '',
    ixc_base_url: '',
    ixc_api_token: '',
    ixc_self_signed: false,
    ixc_timeout_seconds: 15,
    ixc_enabled: false,
    ai_enabled: false,
    ai_internal_chat_enabled: false,
  });

  const canAccess = useMemo(
    () => user?.role === 'system_admin' || user?.role === 'reseller_admin',
    [user?.role]
  );
  const { loginPath, dashboardPath } = getScopedAuthPaths(location.pathname);

  useEffect(() => {
    if (!id) {
      setError('Empresa invalida.');
      setLoading(false);
      return;
    }

    let canceled = false;
    setLoading(true);
    setError('');

    api
      .get(`/admin/empresas/${id}`)
      .then((response) => {
        if (canceled) return;
        const company = response?.data?.company;
        if (!company?.id) {
          setError('Empresa nao encontrada.');
          return;
        }

        setForm({
          name: String(company.name ?? ''),
          meta_phone_number_id: String(company.meta_phone_number_id ?? ''),
          meta_waba_id: String(company.meta_waba_id ?? ''),
          ixc_base_url: String(company.ixc_base_url ?? ''),
          ixc_api_token: '',
          ixc_self_signed: Boolean(company.ixc_self_signed),
          ixc_timeout_seconds: Number(company.ixc_timeout_seconds ?? 15),
          ixc_enabled: Boolean(company.ixc_enabled),
          ai_enabled: Boolean(company.bot_setting?.ai_enabled),
          ai_internal_chat_enabled: Boolean(company.bot_setting?.ai_internal_chat_enabled),
        });
      })
      .catch((err) => {
        if (canceled) return;
        if (err?.status === 404) {
          setError('Empresa nao encontrada ou sem permissao de acesso.');
          return;
        }
        if (err?.status === 403) {
          setError('Voce nao tem permissao para editar esta empresa.');
          return;
        }
        setError(err?.message || 'Nao foi possivel carregar a empresa.');
      })
      .finally(() => {
        if (!canceled) setLoading(false);
      });

    return () => {
      canceled = true;
    };
  }, [id]);

  const handleSubmit = async (event) => {
    event.preventDefault();
    setSaving(true);
    setError('');
    setSuccess('');

    try {
      const payload = {
        name: String(form.name ?? '').trim(),
        meta_phone_number_id: String(form.meta_phone_number_id ?? '').trim() || null,
        meta_waba_id: String(form.meta_waba_id ?? '').trim() || null,
        ixc_base_url: String(form.ixc_base_url ?? '').trim() || null,
        ixc_self_signed: Boolean(form.ixc_self_signed),
        ixc_timeout_seconds: Math.max(5, Math.min(60, Number(form.ixc_timeout_seconds || 15))),
        ixc_enabled: Boolean(form.ixc_enabled),
        ai_enabled: Boolean(form.ai_enabled),
        ai_internal_chat_enabled: Boolean(form.ai_internal_chat_enabled),
      };
      if (String(form.ixc_api_token ?? '').trim() !== '') {
        payload.ixc_api_token = String(form.ixc_api_token).trim();
      }

      await api.put(`/admin/empresas/${id}`, payload);
      setSuccess('Empresa atualizada com sucesso.');
    } catch (err) {
      if (err?.status === 404) {
        setError('Empresa nao encontrada ou sem permissao de acesso.');
      } else if (err?.status === 403) {
        setError('Voce nao tem permissao para editar esta empresa.');
      } else {
        setError(err?.message || 'Falha ao salvar empresa.');
      }
    } finally {
      setSaving(false);
    }
  };

  if (authLoading) {
    return (
      <Layout role="admin" onLogout={logout}>
        <PageLoading rows={2} cards={1} />
      </Layout>
    );
  }

  if (!user) {
    return <Navigate to={loginPath} replace />;
  }

  if (!canAccess) {
    return <Navigate to={dashboardPath} replace />;
  }

  if (loading) {
    return (
      <Layout role="admin" onLogout={logout}>
        <PageLoading rows={2} cards={1} />
      </Layout>
    );
  }

  return (
    <Layout role="admin" onLogout={logout}>
      <button
        type="button"
        onClick={() => navigate('/admin/empresas')}
        className="app-btn-secondary mb-5"
      >
        Voltar
      </button>

      <h1 className="app-page-title">Editar empresa</h1>
      <p className="app-page-subtitle mb-6">Dados basicos da empresa</p>

      <section className="app-panel">
        {error ? <p className="text-sm text-red-600 mb-3">{error}</p> : null}
        {success ? <p className="text-sm text-green-700 mb-3">{success}</p> : null}

        <form onSubmit={handleSubmit} className="grid grid-cols-1 md:grid-cols-2 gap-3">
          <label className="block text-sm md:col-span-2">
            Nome
            <input
              type="text"
              value={form.name}
              onChange={(event) => setForm((prev) => ({ ...prev, name: event.target.value }))}
              required
              className="app-input"
            />
          </label>

          <label className="block text-sm">
            ID do numero (Meta / WhatsApp)
            <input
              type="text"
              value={form.meta_phone_number_id}
              onChange={(event) => setForm((prev) => ({ ...prev, meta_phone_number_id: event.target.value }))}
              className="app-input"
            />
          </label>

          <label className="block text-sm">
            WABA ID
            <input
              type="text"
              value={form.meta_waba_id}
              onChange={(event) => setForm((prev) => ({ ...prev, meta_waba_id: event.target.value }))}
              className="app-input"
            />
          </label>

          <label className="block text-sm md:col-span-2">
            URL base IXC
            <input
              type="url"
              value={form.ixc_base_url}
              onChange={(event) => setForm((prev) => ({ ...prev, ixc_base_url: event.target.value }))}
              className="app-input"
            />
          </label>

          <label className="block text-sm md:col-span-2">
            Token IXC
            <input
              type="password"
              value={form.ixc_api_token}
              onChange={(event) => setForm((prev) => ({ ...prev, ixc_api_token: event.target.value }))}
              className="app-input"
              placeholder="Preencher apenas para atualizar"
            />
          </label>

          <label className="block text-sm">
            Timeout IXC (segundos)
            <input
              type="number"
              min={5}
              max={60}
              value={form.ixc_timeout_seconds}
              onChange={(event) => setForm((prev) => ({ ...prev, ixc_timeout_seconds: Number(event.target.value) }))}
              className="app-input"
            />
          </label>

          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={Boolean(form.ixc_self_signed)}
              onChange={(event) => setForm((prev) => ({ ...prev, ixc_self_signed: event.target.checked }))}
            />
            Permitir certificado autoassinado (IXC)
          </label>

          <label className="flex items-center gap-2 text-sm md:col-span-2">
            <input
              type="checkbox"
              checked={Boolean(form.ixc_enabled)}
              onChange={(event) => setForm((prev) => ({ ...prev, ixc_enabled: event.target.checked }))}
            />
            Habilitar modulo IXC para esta empresa
          </label>

          <label className="flex items-center gap-2 text-sm md:col-span-2">
            <input
              type="checkbox"
              checked={Boolean(form.ai_enabled)}
              onChange={(event) => setForm((prev) => ({ ...prev, ai_enabled: event.target.checked }))}
            />
            Habilitar IA para esta empresa
          </label>

          <label className="flex items-center gap-2 text-sm md:col-span-2">
            <input
              type="checkbox"
              checked={Boolean(form.ai_internal_chat_enabled)}
              onChange={(event) =>
                setForm((prev) => ({ ...prev, ai_internal_chat_enabled: event.target.checked }))
              }
            />
            Habilitar chat interno com IA
          </label>

          <div className="md:col-span-2">
            <button type="submit" disabled={saving} className="app-btn-primary">
              {saving ? 'Salvando...' : 'Salvar'}
            </button>
          </div>
        </form>
      </section>
    </Layout>
  );
}

export default CompanyEditPage;
