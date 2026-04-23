import './AdminCompaniesPage.css';
import { useEffect, useState } from 'react';
import Layout from '@/components/layout/Layout/Layout.jsx';
import usePageData from '@/hooks/usePageData';
import useLogout from '@/hooks/useLogout';
import api from '@/services/api';
import LoadingSkeleton from '@/components/ui/LoadingSkeleton/LoadingSkeleton.jsx';
import EmptyState from '@/components/ui/EmptyState/EmptyState.jsx';
import ConfirmDialog from '@/components/ui/ConfirmDialog/ConfirmDialog.jsx';

function extractFirstValidationError(error) {
  const validationErrors = error?.validationErrors;
  if (!validationErrors || typeof validationErrors !== 'object') {
    return '';
  }

  for (const messages of Object.values(validationErrors)) {
    if (Array.isArray(messages) && messages.length > 0) {
      const first = String(messages[0] ?? '').trim();
      if (first) {
        return first;
      }
    }
  }

  return '';
}

function normalizeHexColor(value, fallback = '#2563eb') {
  const raw = String(value ?? '').trim();
  if (/^#[0-9a-fA-F]{6}$/.test(raw)) {
    return raw.toLowerCase();
  }

  return fallback;
}

function companyStatusLabel(company) {
  const isActive = Boolean(company?.bot_setting?.is_active ?? company?.botSetting?.is_active ?? false);
  return isActive ? 'Ativa' : 'Inativa';
}

function AdminCompaniesPage() {
  const { data, loading, error } = usePageData('/admin/empresas');
  const { logout } = useLogout();
  const [newCompany, setNewCompany] = useState({
    name: '',
    meta_phone_number_id: '',
    ai_enabled: false,
    ai_internal_chat_enabled: false,
  });
  const [createBusy, setCreateBusy] = useState(false);
  const [createError, setCreateError] = useState('');
  const [createSuccess, setCreateSuccess] = useState('');
  const [deleteBusy, setDeleteBusy] = useState(false);
  const [deleteError, setDeleteError] = useState('');
  const [deleteTarget, setDeleteTarget] = useState(null);
  const [companies, setCompanies] = useState([]);
  const [resellers, setResellers] = useState([]);
  const [resellerLoading, setResellerLoading] = useState(true);
  const [resellerForm, setResellerForm] = useState({
    name: '',
    slug: '',
    primary_color: '#2563eb',
    logo: null,
  });
  const [resellerBusy, setResellerBusy] = useState(false);
  const [resellerError, setResellerError] = useState('');
  const [resellerSuccess, setResellerSuccess] = useState('');
  const [editingResellerId, setEditingResellerId] = useState(null);

  useEffect(() => {
    setCompanies(Array.isArray(data?.companies) ? data.companies : []);
  }, [data]);

  useEffect(() => {
    let canceled = false;
    setResellerLoading(true);

    api
      .get('/admin/resellers')
      .then((response) => {
        if (canceled) return;
        const list = Array.isArray(response?.data?.resellers) ? response.data.resellers : [];
        setResellers(list);
      })
      .catch(() => {
        if (canceled) return;
        setResellers([]);
      })
      .finally(() => {
        if (!canceled) {
          setResellerLoading(false);
        }
      });

    return () => {
      canceled = true;
    };
  }, []);

  const resetResellerForm = () => {
    setResellerForm({
      name: '',
      slug: '',
      primary_color: '#2563eb',
      logo: null,
    });
    setEditingResellerId(null);
  };

  const applyResellerToForm = (reseller) => {
    setEditingResellerId(reseller?.id ?? null);
    setResellerForm({
      name: String(reseller?.name ?? ''),
      slug: String(reseller?.slug ?? ''),
      primary_color: normalizeHexColor(reseller?.primary_color, '#2563eb'),
      logo: null,
    });
    setResellerError('');
    setResellerSuccess('');
  };

  const handleResellerColorChange = (value) => {
    const normalized = normalizeHexColor(value, resellerForm.primary_color || '#2563eb');
    setResellerForm((previous) => ({ ...previous, primary_color: normalized }));
  };

  const handleSaveReseller = async (event) => {
    event.preventDefault();
    setResellerBusy(true);
    setResellerError('');
    setResellerSuccess('');

    const formData = new FormData();
    formData.append('name', String(resellerForm.name ?? '').trim());
    formData.append('slug', String(resellerForm.slug ?? '').trim().toLowerCase());
    formData.append('primary_color', normalizeHexColor(resellerForm.primary_color, '#2563eb'));
    if (resellerForm.logo instanceof File) {
      formData.append('logo', resellerForm.logo);
    }

    try {
      let response;
      if (editingResellerId) {
        formData.append('_method', 'PUT');
        response = await api.post(`/admin/resellers/${editingResellerId}`, formData, {
          headers: { 'Content-Type': 'multipart/form-data' },
        });
      } else {
        response = await api.post('/admin/resellers', formData, {
          headers: { 'Content-Type': 'multipart/form-data' },
        });
      }

      const saved = response?.data?.reseller;
      if (saved?.id) {
        setResellers((previous) => {
          const others = previous.filter((item) => item.id !== saved.id);
          return [...others, saved].sort((left, right) =>
            String(left?.name ?? '').localeCompare(String(right?.name ?? ''), 'pt-BR')
          );
        });
      }

      setResellerSuccess(editingResellerId ? 'Reseller atualizado com sucesso.' : 'Reseller criado com sucesso.');
      resetResellerForm();
    } catch (err) {
      const firstValidationError = extractFirstValidationError(err);
      setResellerError(firstValidationError || err?.message || 'Falha ao salvar reseller.');
    } finally {
      setResellerBusy(false);
    }
  };

  const handleCreateCompany = async (event) => {
    event.preventDefault();
    setCreateBusy(true);
    setCreateError('');
    setCreateSuccess('');

    try {
      const payload = {
        name: newCompany.name,
        meta_phone_number_id: newCompany.meta_phone_number_id || null,
        ai_enabled: Boolean(newCompany.ai_enabled),
        ai_internal_chat_enabled: Boolean(newCompany.ai_internal_chat_enabled),
      };
      const response = await api.post('/admin/empresas', payload);
      const created = response.data?.company;
      setCreateSuccess(`Empresa criada: ${created?.name ?? payload.name}`);
      setNewCompany({
        name: '',
        meta_phone_number_id: '',
        ai_enabled: false,
        ai_internal_chat_enabled: false,
      });
      if (created?.id) {
        setCompanies((previous) =>
          [...previous, { ...created, conversations_count: 0, bot_setting: null }].sort((left, right) =>
            String(left?.name ?? '').localeCompare(String(right?.name ?? ''), 'pt-BR')
          )
        );
      }
    } catch (err) {
      setCreateError(err.response?.data?.message || 'Falha ao criar empresa.');
    } finally {
      setCreateBusy(false);
    }
  };

  const confirmDeleteCompany = async () => {
    if (!deleteTarget?.id) return;

    setDeleteBusy(true);
    setDeleteError('');
    try {
      await api.delete(`/admin/empresas/${deleteTarget.id}`);
      setCompanies((prev) => prev.filter((c) => c.id !== deleteTarget.id));
      setDeleteTarget(null);
    } catch (err) {
      setDeleteError(err.response?.data?.message || 'Falha ao excluir empresa.');
    } finally {
      setDeleteBusy(false);
    }
  };

  if (loading) {
    return (
      <Layout role="admin" onLogout={logout}>
        <div className="space-y-3">
          <LoadingSkeleton className="h-6 w-40" />
          <LoadingSkeleton className="h-4 w-96 max-w-full" />
          <LoadingSkeleton className="h-28 w-full" />
          <LoadingSkeleton className="h-16 w-full" />
          <LoadingSkeleton className="h-16 w-full" />
        </div>
      </Layout>
    );
  }

  if (error || !data?.authenticated) {
    return (
      <Layout>
        <p className="text-sm text-red-600 dark:text-red-400">Não foi possível carregar as empresas.</p>
      </Layout>
    );
  }

  return (
    <Layout role="admin" onLogout={logout}>
      <h1 className="app-page-title">Empresas</h1>
      <p className="app-page-subtitle mb-6">
        Lista de empresas com acesso. Clique para ver informações e uso.
      </p>

      <section className="app-panel mb-8">
        <h2 className="font-medium mb-3">Resellers</h2>
        <form onSubmit={handleSaveReseller} className="grid grid-cols-1 md:grid-cols-2 gap-3">
          <label className="block text-sm">
            Nome
            <input
              type="text"
              value={resellerForm.name}
              onChange={(event) => setResellerForm((previous) => ({ ...previous, name: event.target.value }))}
              required
              className="app-input"
            />
          </label>

          <label className="block text-sm">
            Slug
            <input
              type="text"
              value={resellerForm.slug}
              onChange={(event) => setResellerForm((previous) => ({ ...previous, slug: event.target.value }))}
              required
              className="app-input"
              placeholder="minha-marca"
            />
          </label>

          <label className="block text-sm">
            Logo (upload)
            <input
              type="file"
              accept="image/*"
              onChange={(event) =>
                setResellerForm((previous) => ({ ...previous, logo: event.target.files?.[0] ?? null }))
              }
              className="app-input"
            />
          </label>

          <label className="block text-sm">
            Cor primaria
            <div className="mt-1.5 flex items-center gap-2">
              <input
                type="color"
                value={normalizeHexColor(resellerForm.primary_color)}
                onChange={(event) => handleResellerColorChange(event.target.value)}
                className="h-10 w-14 rounded border border-[#d4d4d4] bg-white p-1"
              />
              <input
                type="text"
                value={resellerForm.primary_color}
                onChange={(event) => handleResellerColorChange(event.target.value)}
                className="app-input !mt-0"
                placeholder="#2563eb"
              />
            </div>
          </label>

          <div className="md:col-span-2 flex items-center gap-2">
            <button type="submit" disabled={resellerBusy} className="app-btn-primary">
              {resellerBusy
                ? 'Salvando...'
                : editingResellerId
                  ? 'Salvar reseller'
                  : 'Criar reseller'}
            </button>
            {editingResellerId ? (
              <button type="button" className="app-btn-secondary" onClick={resetResellerForm} disabled={resellerBusy}>
                Cancelar edicao
              </button>
            ) : null}
          </div>
        </form>

        {resellerError ? <p className="text-sm text-red-600 mt-2">{resellerError}</p> : null}
        {resellerSuccess ? <p className="text-sm text-green-700 mt-2">{resellerSuccess}</p> : null}

        <div className="mt-5">
          {resellerLoading ? (
            <p className="text-sm text-[#737373]">Carregando resellers...</p>
          ) : resellers.length === 0 ? (
            <p className="text-sm text-[#737373]">Nenhum reseller cadastrado.</p>
          ) : (
            <ul className="rounded-xl border border-[#eeeeee] overflow-hidden bg-white divide-y divide-[#eeeeee]">
              {resellers.map((reseller) => (
                <li key={reseller.id} className="px-4 py-3 flex items-center justify-between gap-3">
                  <div className="min-w-0">
                    <p className="font-medium text-[#171717] truncate">{reseller.name}</p>
                    <p className="text-xs text-[#737373] truncate">/{reseller.slug}</p>
                  </div>
                  <div className="flex items-center gap-2">
                    {reseller.logo_url ? (
                      <img
                        src={reseller.logo_url}
                        alt={reseller.name || 'Logo do reseller'}
                        className="h-7 w-7 rounded object-cover border border-[#e5e5e5]"
                      />
                    ) : (
                      <span
                        title={reseller.primary_color || 'Sem cor definida'}
                        className="inline-block h-7 w-7 rounded border border-[#d4d4d4]"
                        style={{ background: normalizeHexColor(reseller.primary_color, '#f5f5f5') }}
                      />
                    )}
                    <button
                      type="button"
                      className="app-btn-secondary text-xs px-3 py-1.5"
                      onClick={() => applyResellerToForm(reseller)}
                    >
                      Editar
                    </button>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </div>
      </section>

      <section className="app-panel mb-8">
        <h2 className="font-medium mb-3">Criar empresa</h2>
        <form onSubmit={handleCreateCompany} className="grid grid-cols-1 md:grid-cols-2 gap-3">
          <label className="block text-sm md:col-span-2">
            Nome da empresa
            <input
              type="text"
              value={newCompany.name}
              onChange={(e) => setNewCompany((p) => ({ ...p, name: e.target.value }))}
              required
              className="app-input"
            />
          </label>

          <label className="block text-sm">
            ID do número (Meta / WhatsApp)
            <input
              type="text"
              value={newCompany.meta_phone_number_id}
              onChange={(e) => setNewCompany((p) => ({ ...p, meta_phone_number_id: e.target.value }))}
              className="app-input"
            />
          </label>

          <label className="flex items-center gap-2 text-sm md:col-span-2">
            <input
              type="checkbox"
              checked={Boolean(newCompany.ai_enabled)}
              onChange={(e) => setNewCompany((p) => ({ ...p, ai_enabled: e.target.checked }))}
            />
            Habilitar IA para esta empresa
          </label>

          <label className="flex items-center gap-2 text-sm md:col-span-2">
            <input
              type="checkbox"
              checked={Boolean(newCompany.ai_internal_chat_enabled)}
              onChange={(e) =>
                setNewCompany((p) => ({ ...p, ai_internal_chat_enabled: e.target.checked }))
              }
            />
            Habilitar chat interno com IA
          </label>

          <div className="md:col-span-2">
            <button
              type="submit"
              disabled={createBusy}
              className="app-btn-primary"
            >
              {createBusy ? 'Criando...' : 'Criar empresa'}
            </button>
          </div>
        </form>
        {createError && <p className="text-sm text-red-600 mt-2">{createError}</p>}
        {createSuccess && <p className="text-sm text-green-700 mt-2">{createSuccess}</p>}
        {deleteError && <p className="text-sm text-red-600 mt-2">{deleteError}</p>}
      </section>

      {!companies.length ? (
        <EmptyState
          title="Nenhuma empresa cadastrada"
          subtitle="Crie uma empresa para iniciar o acompanhamento e configuracao do bot."
        />
      ) : (
        <ul className="rounded-xl border border-[#eeeeee] overflow-hidden bg-white divide-y divide-[#eeeeee]">
          {companies.map((company) => (
            <li key={company.id} className="px-5 py-4 flex items-center justify-between gap-3 hover:bg-[#fafafa] transition">
              <div className="flex-1 min-w-0">
                <p className="font-medium text-[#171717] truncate">{company.name}</p>
                <p className="text-sm text-[#737373]">Status: {companyStatusLabel(company)}</p>
              </div>
              <div className="flex items-center gap-2">
                <a href={`/companies/${company.id}/edit`} className="app-btn-secondary text-xs px-3 py-1.5">
                  Editar
                </a>
                <button
                  type="button"
                  className="app-btn-danger text-xs px-3 py-1.5"
                  onClick={() => {
                    setDeleteError('');
                    setDeleteTarget(company);
                  }}
                >
                  Excluir
                </button>
              </div>
            </li>
          ))}
        </ul>
      )}

      <ConfirmDialog
        open={Boolean(deleteTarget)}
        title="Excluir empresa"
        description={
          deleteTarget
            ? `Tem certeza que deseja excluir a empresa "${deleteTarget.name}"? Esta ação não pode ser desfeita.`
            : ''
        }
        confirmLabel="Excluir"
        confirmTone="danger"
        busy={deleteBusy}
        onClose={() => {
          if (!deleteBusy) setDeleteTarget(null);
        }}
        onConfirm={() => void confirmDeleteCompany()}
      />
    </Layout>
  );
}

export default AdminCompaniesPage;

