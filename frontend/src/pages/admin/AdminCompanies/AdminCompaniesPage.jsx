import './AdminCompaniesPage.css';
import { useEffect, useState } from 'react';
import Layout from '@/components/layout/Layout/Layout.jsx';
import usePageData from '@/hooks/usePageData';
import useLogout from '@/hooks/useLogout';
import api from '@/services/api';
import LoadingSkeleton from '@/components/ui/LoadingSkeleton/LoadingSkeleton.jsx';
import EmptyState from '@/components/ui/EmptyState/EmptyState.jsx';
import ConfirmDialog from '@/components/ui/ConfirmDialog/ConfirmDialog.jsx';

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

  useEffect(() => {
    setCompanies(Array.isArray(data?.companies) ? data.companies : []);
  }, [data]);

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
              <a
                href={`/admin/empresas/${company.id}`}
                className="flex-1 min-w-0"
              >
                <span className="font-medium text-[#171717]">{company.name}</span>
                <span className="text-sm text-[#737373] ml-2">
                  · {company.conversations_count ?? 0} conversa(s)
                </span>
                <span className="text-xs text-[#a3a3a3] ml-2">
                  · bot {company.bot_setting ? 'configurado' : 'padrão'}
                </span>
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
            </li>
          ))}
        </ul>
      )}

      <ConfirmDialog
        open={Boolean(deleteTarget)}
        title="Excluir empresa"
        description={
          deleteTarget
            ? `Tem certeza que deseja excluir a empresa "${deleteTarget.name}"? Esta acao nao pode ser desfeita.`
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




